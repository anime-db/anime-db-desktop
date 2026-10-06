<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\MessageHandler;

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use App\Entity\ValueObject\PluginId;
use App\Message\RemoveFromSourceMessage;
use App\Message\SyncSeedMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Deletes one title from the user's list on a source after a local deletion (issue #918). What to do
 * is decided by {@see SourceRemovalService}, the message only wakes this up.
 *
 * The plugin is re-resolved through {@see SyncRegistry::findByPluginId()}: one switched off since is
 * skipped and the pending flag stays for the catch-up before its next pull. The plugin's seed
 * lock ({@see SyncSeedMessage::jobKey()}) is taken for the call, so no seed/pull backfill runs in
 * parallel; if it is already held the removal is skipped too, like in
 * {@see PushSyncMessageHandler}: the catch-up of that very seed or pull handles it.
 *
 * The external-id backfill of the plugin runs before the removal, under the same lock: a live entry
 * with a source URL but no cached id must be linked first, so the pair is seen as held and the list
 * item stays. A backfill failure is logged and does not stop the removal.
 *
 * A transient failure is left to propagate to the `async` transport's retry strategy. A dead
 * authorization is not transient: it is logged and thrown as unrecoverable, the flag stays.
 */
#[AsMessageHandler]
final class RemoveFromSourceMessageHandler
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly ExternalIdBackfillService $backfillService,
        private readonly SourceRemovalService $removalService,
        private readonly JobLockService $jobLockService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RemoveFromSourceMessage $message): void
    {
        $pluginId = new PluginId($message->pluginId);
        $sync = $this->syncRegistry->findByPluginId($pluginId);
        if ($sync === null) {
            $this->logger->info('Sync plugin "{plugin}" is no longer active; its pending removal of "{externalId}" stays for later.', [
                'plugin' => $message->pluginId,
                'externalId' => $message->externalId,
            ]);

            return;
        }

        $jobKey = SyncSeedMessage::jobKey($message->pluginId);
        if (!$this->jobLockService->acquire($jobKey)) {
            $this->logger->info('A sync of "{plugin}" is running; its pending removal of "{externalId}" is left to that run.', [
                'plugin' => $message->pluginId,
                'externalId' => $message->externalId,
            ]);

            return;
        }

        try {
            try {
                $this->backfillService->backfill($pluginId, $sync);
            } catch (\Throwable $exception) {
                $this->logger->warning('External id backfill of "{plugin}" failed before a pending removal of "{externalId}"; continuing.', [
                    'plugin' => $message->pluginId,
                    'externalId' => $message->externalId,
                    'exception' => $exception,
                ]);
            }

            $this->removalService->remove($pluginId, $sync, $message->externalId);
        } catch (ReauthRequiredException $exception) {
            $this->logger->warning('Sync plugin "{plugin}" needs reauthorization; its pending removal of "{externalId}" stays, not retrying.', [
                'plugin' => $message->pluginId,
                'externalId' => $message->externalId,
                'exception' => $exception,
            ]);

            throw new UnrecoverableMessageHandlingException(\sprintf('Sync plugin "%s" needs reauthorization, not retrying this message.', $message->pluginId));
        } finally {
            $this->jobLockService->release($jobKey);
        }
    }
}
