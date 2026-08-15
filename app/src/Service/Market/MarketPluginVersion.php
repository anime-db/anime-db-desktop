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

/**
 * One entry of a {@see MarketPlugin}'s `versions` list: a published version number together
 * with the full `require.core` constraint string (e.g. `">=2.1 <3.0"`) that version declares.
 * The `sha256` pinned for this version lives on {@see PluginRegistry} instead
 * ({@see PluginRegistry::findVersionSha256()}) — it is keyed by plugin id/version and consumed
 * by {@see MarketAssetDownloader}, which never needs the rest of this DTO.
 */
final class MarketPluginVersion
{
    public function __construct(
        public readonly string $version,
        public readonly string $core,
    ) {
    }
}
