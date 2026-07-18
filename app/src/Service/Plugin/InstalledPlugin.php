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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Manifest\Manifest;
use App\Entity\ValueObject\PluginId;

/**
 * A single entry of {@see InstalledPluginsRegistry}: the plugin's parsed `manifest.json`, the
 * absolute path it is unpacked into, and whether it is currently active.
 *
 * `enabled` is a snapshot taken from {@see PluginsConfigStore} at read time, not a second copy
 * persisted in the on-disk index — see {@see InstalledPluginsRegistry} for why.
 */
final class InstalledPlugin
{
    public readonly PluginId $id;

    public function __construct(
        public readonly Manifest $manifest,
        public readonly string $installPath,
        public readonly bool $enabled,
    ) {
        $this->id = new PluginId($manifest->id);
    }
}
