<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Plugin\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Plugin\ZipPluginInstaller::install()} when the plugin id from the
 * unpacked archive's manifest.json already has a directory at `%app.plugins_dir%/<pluginId>/`,
 * whether or not {@see \App\Service\Plugin\InstalledPluginsRegistry} currently has an index entry
 * for it — a stray directory left over from a previous failed install is treated the same as a
 * genuinely already-installed plugin, since either way this installer must not overwrite it.
 * Updating an already-installed plugin is a separate feature (issue #224).
 */
final class PluginAlreadyInstalledException extends \RuntimeException
{
    public function __construct(public readonly PluginId $pluginId)
    {
        parent::__construct(\sprintf('Plugin "%s" is already installed.', $pluginId));
    }
}
