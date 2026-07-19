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

namespace App\Service\Storage\Scan;

use AnimeDb\PluginContracts\SearchByPluginCandidate;
use App\Entity\Anime;

/**
 * A single suggestion for a new storage file/folder, from either of the two sources
 * ScanStorageService combines: an existing orphan Anime found by OrphanAnimeMatcher, or a
 * match reported by SearchByPluginChain. Exactly one of the two is ever set. ScanStorageService
 * merges orphans and plugin matches by normalized name before building this list, so a plugin
 * candidate that agrees with an orphan (or with another plugin candidate) collapses into one
 * entry rather than appearing twice.
 */
final class ScanCandidate
{
    private function __construct(
        public readonly ?Anime $orphan,
        public readonly ?SearchByPluginCandidate $plugin,
    ) {
    }

    public static function fromOrphan(Anime $orphan): self
    {
        return new self($orphan, null);
    }

    public static function fromPlugin(SearchByPluginCandidate $plugin): self
    {
        return new self(null, $plugin);
    }
}
