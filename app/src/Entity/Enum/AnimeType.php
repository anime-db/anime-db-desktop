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

namespace App\Entity\Enum;

use App\Entity\Anime;
use App\Entity\MovieAnime;
use App\Entity\MusicAnime;
use App\Entity\OnaAnime;
use App\Entity\OvaAnime;
use App\Entity\SpecialAnime;
use App\Entity\TvAnime;

enum AnimeType: string
{
    case Tv = 'tv';
    case Movie = 'movie';
    case Ova = 'ova';
    case Ona = 'ona';
    case Special = 'special';
    case Music = 'music';

    /**
     * Single source of truth for the type-to-class mapping: both Anime::migrate() and
     * AnimeRepository need it, and it must not drift between the two (issue #74 review).
     *
     * @return class-string<Anime>
     */
    public function entityClass(): string
    {
        return match ($this) {
            self::Tv => TvAnime::class,
            self::Movie => MovieAnime::class,
            self::Ova => OvaAnime::class,
            self::Ona => OnaAnime::class,
            self::Special => SpecialAnime::class,
            self::Music => MusicAnime::class,
        };
    }
}
