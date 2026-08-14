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

use App\Entity\ValueObject\PluginId;

/**
 * Derives an asset's download URL from one of the registry's `asset_mirrors` templates by
 * substituting its `<id>`/`<version>`/`<file>` macros. The registry never stores asset URLs
 * directly (only the mirror templates and each version's `sha256`) — see
 * `plugins_marketplace.md` §2 in the workspace notes — so every caller derives the URL itself.
 */
final class PluginAssetUrlResolver
{
    public function resolve(string $mirrorUrlTemplate, PluginId $pluginId, string $version, string $file): string
    {
        return strtr($mirrorUrlTemplate, [
            '<id>' => (string) $pluginId,
            '<version>' => $version,
            '<file>' => $file,
        ]);
    }
}
