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

namespace App\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\Service\Plugin\Exception\PluginsConfigStoreLockedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the one-time connect-seed ({@see SyncSeedMessage}, a full pull) for a plugin that has no
 * settings page to trigger it from. A `syncSeeded` flag on the plugin's entry in
 * {@see PluginsConfigStore}, checked and set atomically under its `updatePluginSettings()` lock,
 * makes the dispatch happen at most once; the message handler resets the flag itself when the pull
 * stops short, so a later call retries.
 *
 * A contended lock skips the dispatch (logged) instead of failing the caller.
 */
class SyncSeedDispatcher
{
    public function __construct(
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether a {@see SyncSeedMessage} was dispatched by this call
     */
    public function dispatchIfNotSeeded(PluginId $id): bool
    {
        $alreadySeeded = false;

        try {
            $this->pluginsConfigStore->updatePluginSettings($id, static function (array $settings) use (&$alreadySeeded): array {
                if (($settings['syncSeeded'] ?? false) === true) {
                    $alreadySeeded = true;

                    return $settings;
                }

                $settings['syncSeeded'] = true;

                return $settings;
            });
        } catch (PluginsConfigStoreLockedException $exception) {
            $this->logger->info('Could not mark connect-seed as seeded because the plugins config store lock was exhausted; skipping seed dispatch.', [
                'pluginId' => (string) $id,
                'exception' => $exception,
            ]);

            return false;
        }

        if ($alreadySeeded) {
            return false;
        }

        $this->messageBus->dispatch(new SyncSeedMessage((string) $id));

        return true;
    }
}
