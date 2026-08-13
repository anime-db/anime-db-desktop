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

use App\Entity\ValueObject\PluginId;
use App\Message\BackfillExternalIdMessage;
use App\Repository\AnimeRepository;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\SyncRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One-off catalog sweep (issue #258) that resolves and caches a newly installed sync
 * plugin's external id for every Anime that already carries a matching source URL, via
 * {@see Anime::getExternalId()} (issue #211) — instead of leaving it to be resolved lazily,
 * one record at a time, the first time pull-dedup (issue #215a) or cross-vendor dedup
 * (issue #216) needs it.
 *
 * Guarded by {@see JobLockService} under `sync_backfill:<pluginId>`, same convention as
 * ScanStorageMessageHandler's per-storage lock: two overlapping backfills of the same
 * plugin (e.g. the message got redelivered) would otherwise race on the same rows for no
 * benefit. If the plugin is no longer installed or sync is no longer enabled for it by the
 * time this runs (the user disabled it right after installing), {@see SyncRegistry} simply
 * won't return it and this is a silent no-op — same "nothing left to do" stance as
 * PushSyncMessageHandler's missing-anime case, not a failure worth retrying.
 *
 * The catalog is walked page by page via {@see AnimeRepository::findPage()} (same
 * LIMIT/OFFSET + EntityManager::clear() pattern as AnimeReindexService::reindexAll(), for the
 * same reason: never materialize the whole catalog in memory at once) rather than filtered
 * down to only-unresolved rows in SQL: a plain full-table walk keeps pagination correct
 * regardless of how many rows this batch resolves (removing rows from the WHERE clause
 * mid-walk would shift the OFFSET under a fixed-size page). Rows that already carry a cached
 * id for this plugin are skipped without calling resolveExternalId() again. A single
 * record's resolveExternalId() throwing is
 * logged and skipped, not fatal for the rest of the sweep; each page is flushed and the lock
 * heartbeat is refreshed before moving to the next one, so an interrupted run (crash, app
 * closed) never loses more than one page of progress and a re-dispatch of the same message
 * simply resumes — already-resolved rows are skipped again, nothing is double-processed.
 */
#[AsMessageHandler]
final class BackfillExternalIdMessageHandler
{
    private const int PAGE_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeRepository $animeRepository,
        private readonly JobLockService $jobLockService,
        private readonly SyncRegistry $syncRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BackfillExternalIdMessage $message): void
    {
        $pluginId = new PluginId($message->pluginId);
        $jobKey = \sprintf('sync_backfill:%s', $pluginId);
        $lockAcquired = false;

        try {
            if (!$this->jobLockService->acquire($jobKey)) {
                $this->logger->info('Skipping external id backfill: already running for this plugin.', [
                    'plugin_id' => (string) $pluginId,
                    'job_key' => $jobKey,
                ]);

                return;
            }
            $lockAcquired = true;

            $sync = $this->syncRegistry->findByPluginId($pluginId);
            if ($sync === null) {
                $this->logger->info('Skipping external id backfill: plugin is not installed or sync is not active.', [
                    'plugin_id' => (string) $pluginId,
                ]);

                return;
            }

            $processed = 0;
            $set = 0;
            $skipped = 0;
            $offset = 0;

            do {
                $page = $this->animeRepository->findPage($offset, self::PAGE_SIZE);

                foreach ($page as $anime) {
                    if ($anime->getCachedExternalId($pluginId) !== null) {
                        continue;
                    }

                    ++$processed;

                    try {
                        $externalId = $anime->getExternalId($pluginId, $sync);
                    } catch (\Throwable $exception) {
                        $this->logger->error('Skipping anime during external id backfill: resolveExternalId() failed.', [
                            'plugin_id' => (string) $pluginId,
                            'anime_id' => $anime->id,
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    $externalId !== null ? ++$set : ++$skipped;
                }

                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->jobLockService->heartbeat($jobKey);

                $offset += self::PAGE_SIZE;
            } while (\count($page) === self::PAGE_SIZE);

            $this->logger->info('External id backfill finished.', [
                'plugin_id' => (string) $pluginId,
                'processed' => $processed,
                'set' => $set,
                'skipped' => $skipped,
            ]);
        } finally {
            if ($lockAcquired) {
                $this->jobLockService->release($jobKey);
            }
        }
    }
}
