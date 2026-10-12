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

namespace App\Service\Market;

use App\Service\Notification\Kind\PluginUpdateNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Plugin\InstalledPluginsRegistry;

/**
 * Records a `plugin_update` notification for every installed plugin that has a newer compatible
 * version in the freshly stored market snapshot. The "newer" decision is
 * {@see MarketUpdateResolver::availableUpdate()}'s; the `once` policy of the kind makes repeated
 * calls for the same plugin and version a no-op.
 *
 * Call it only after the snapshot is stored and outside of any transaction.
 */
final class MarketUpdateNotifier
{
    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly MarketUpdateResolver $updateResolver,
        private readonly NotificationCenter $notifications,
    ) {
    }

    public function notify(): void
    {
        foreach ($this->installedPlugins->all() as $plugin) {
            $version = $this->updateResolver->availableUpdate($plugin);
            if ($version === null) {
                continue;
            }

            $this->notifications->record(
                PluginUpdateNotificationKind::draft((string) $plugin->id, $plugin->manifest->name, $version),
            );
        }
    }
}
