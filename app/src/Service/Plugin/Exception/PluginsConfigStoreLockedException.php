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

namespace App\Service\Plugin\Exception;

/**
 * Thrown by {@see \App\Service\Plugin\PluginsConfigStore::updatePluginSettings()} when a bounded
 * number of non-blocking flock() acquire attempts all failed because another writer already
 * holds the lock. The lock is shared by every plugin's entry in plugins.json, including the
 * host's own `enabled`/`features` toggles ({@see \App\Service\Plugin\WidgetActiveTrait}), so a
 * writer must fail fast instead of queueing indefinitely behind a stuck or slow concurrent write
 * (issue #340) — otherwise a hung plugin modifier could block the settings page from even
 * disabling that same plugin.
 *
 * {@see \App\Service\Plugin\SettingsStore::update()} maps this host-internal type onto the
 * contract's {@see \AnimeDb\PluginContracts\Settings\ConcurrentWriteException} at the plugin
 * boundary; internal callers of updatePluginSettings() (e.g. {@see \App\Service\Plugin\WidgetActiveTrait})
 * see this type directly and decide for themselves how to react.
 */
final class PluginsConfigStoreLockedException extends PluginsConfigStoreException
{
    public function __construct(string $path, int $attempts)
    {
        parent::__construct(\sprintf(
            'Could not acquire the lock on "%s" after %d attempt(s): another writer already holds it.',
            $path,
            $attempts,
        ));
    }
}
