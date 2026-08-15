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

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Plugin\ZipPluginInstaller::update()} when the unpacked archive's
 * manifest id has no matching directory at `%app.plugins_dir%/<pluginId>/` and no matching entry
 * in {@see \App\Service\Plugin\InstalledPluginsRegistry} — i.e. there is nothing to update.
 * Updating is a distinct operation from a first-time install ({@see PluginAlreadyInstalledException}
 * is its mirror image over on the install side), so an update attempt for a plugin id that is not
 * currently installed is rejected rather than silently falling back to installing it fresh.
 */
final class PluginNotInstalledException extends \RuntimeException
{
    public function __construct(public readonly PluginId $pluginId)
    {
        parent::__construct(\sprintf('Plugin "%s" is not installed.', $pluginId));
    }
}
