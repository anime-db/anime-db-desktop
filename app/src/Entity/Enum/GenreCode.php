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

/**
 * Starter list is the public Shikimori/MAL genre taxonomy.
 * Extend by adding a new case here and a matching migration adding the value to the CHECK constraint.
 */
enum GenreCode: string
{
    case Action = 'action';
    case Adventure = 'adventure';
    case Comedy = 'comedy';
    case Drama = 'drama';
    case Fantasy = 'fantasy';
    case Horror = 'horror';
    case Mecha = 'mecha';
    case Music = 'music';
    case Mystery = 'mystery';
    case Psychological = 'psychological';
    case Romance = 'romance';
    case SciFi = 'sci-fi';
    case SliceOfLife = 'slice-of-life';
    case Sports = 'sports';
    case Supernatural = 'supernatural';
    case Thriller = 'thriller';
    case Ecchi = 'ecchi';
    case Harem = 'harem';
    case Isekai = 'isekai';
    case Magic = 'magic';
    case MartialArts = 'martial-arts';
    case Military = 'military';
    case Historical = 'historical';
    case Parody = 'parody';
    case School = 'school';
    case Shounen = 'shounen';
    case Shoujo = 'shoujo';
    case Seinen = 'seinen';
    case Josei = 'josei';
    case SuperPower = 'super-power';
    case Vampire = 'vampire';
    case Demons = 'demons';
    case Game = 'game';
    case Kids = 'kids';
    case Dementia = 'dementia';
}
