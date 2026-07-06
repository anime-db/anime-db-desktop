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

namespace App\Tests\Unit\Entity;

use App\Entity\Enum\AnimeType;
use App\Entity\MovieAnime;
use PHPUnit\Framework\TestCase;

final class MovieAnimeTest extends TestCase
{
    public function testGetType(): void
    {
        $anime = new MovieAnime();

        $this->assertSame(AnimeType::Movie, $anime->getType());
    }

    /**
     * MovieAnime is a single-episode anime: episodesCount/watchedEpisodes/watchNextEpisode()
     * belong to SeriesAnime and must not exist as members here, unlike in the pre-#58 design
     * where every Anime had them regardless of type (see .claude-docs/decisions.md).
     */
    public function testHasNoEpisodesCountMember(): void
    {
        $this->assertFalse(method_exists(MovieAnime::class, 'getEpisodesCount'));
        $this->assertFalse(method_exists(MovieAnime::class, 'setEpisodesCount'));
    }

    public function testHasNoWatchedEpisodesMember(): void
    {
        $this->assertFalse(method_exists(MovieAnime::class, 'getWatchedEpisodes'));
        $this->assertFalse(method_exists(MovieAnime::class, 'setWatchedEpisodes'));
    }

    public function testHasNoWatchNextEpisodeMember(): void
    {
        $this->assertFalse(method_exists(MovieAnime::class, 'watchNextEpisode'));
    }
}
