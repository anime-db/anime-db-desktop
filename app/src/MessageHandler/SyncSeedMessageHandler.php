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
use App\Message\SyncSeedMessage;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Connect-seed handler (issue #381): runs the one-time full pull that seeds the catalog right
 * after a sync plugin is enabled, mirroring how {@see PushSyncMessageHandler} consumes
 * {@see SyncRegistry} for the push direction (issue #214). The actual reconciliation — applying
 * agreements straight to local and raising persistent review items for genuine conflicts — is
 * entirely {@see PullSyncService::pull()}'s job; this handler is only the async trigger that
 * keeps that potentially ~1000-item pull off the HTTP request which enabled the plugin.
 *
 * {@see SyncRegistry::findByPluginId()} is re-resolved here rather than trusting the dispatch-time
 * state: the same self-healing stance as {@see PushSyncMessageHandler} takes for a deleted Anime —
 * a plugin the user disabled again before this message was processed simply has nothing left to
 * seed, not an error.
 *
 * `features.sync` alone (what {@see SyncRegistry::findByPluginId()} gates on) does not guarantee
 * the plugin's OAuth is actually complete — {@see \App\Controller\Settings\PluginSettingsController}
 * sets the `syncSeeded` one-time flag before this handler ever runs, purely to make the dispatch
 * itself idempotent. If {@see PullSyncService::pull()} reports it stopped short on a dead/missing
 * OAuth session (its `false` return), the seed never actually happened, so this handler resets
 * `syncSeeded` back to `false` — the user's next visit to the settings page, presumably after
 * finishing OAuth, re-triggers connect-seed instead of the flag silently staying "done" forever.
 */
#[AsMessageHandler]
final class SyncSeedMessageHandler
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly PullSyncService $pullSyncService,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncSeedMessage $message): void
    {
        $pluginId = new PluginId($message->pluginId);
        $sync = $this->syncRegistry->findByPluginId($pluginId);

        if ($sync === null) {
            $this->logger->info('Connect-seed for plugin "{pluginId}" skipped: it is no longer an active sync plugin.', [
                'pluginId' => $message->pluginId,
            ]);

            return;
        }

        $seeded = $this->pullSyncService->pull($pluginId, $sync);
        if (!$seeded) {
            $this->logger->info('Connect-seed for plugin "{pluginId}" did not complete (needs reauthorization); resetting the seeded flag so the next settings-page visit retries it.', [
                'pluginId' => $message->pluginId,
            ]);

            $this->pluginsConfigStore->updatePluginSettings($pluginId, static function (array $settings): array {
                $settings['syncSeeded'] = false;

                return $settings;
            });
        }
    }
}
