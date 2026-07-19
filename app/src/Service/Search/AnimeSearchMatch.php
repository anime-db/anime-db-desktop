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

namespace App\Service\Search;

/**
 * One Meilisearch hit as returned by {@see AnimeSearchResolver::tryResolveMatches()}, carrying
 * the `_rankingScore` (issue #268) alongside the anime id — AnimeSearchResolver::tryResolveIds()
 * has no use for the score (AnimeListController's free-text filter takes every hit as-is), so
 * that method keeps returning a plain id list; only the cross-vendor duplicate heuristic needs
 * the relevance value to threshold against.
 */
final readonly class AnimeSearchMatch
{
    public function __construct(
        public int $id,
        public float $rankingScore,
    ) {
    }
}
