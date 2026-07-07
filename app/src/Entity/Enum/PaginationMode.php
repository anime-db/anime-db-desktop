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
 * User-facing pagination style, chosen in %AppData%/config.json (see AppSettingsProvider).
 * The list endpoint itself is the same LIMIT/OFFSET query regardless of the mode; only the
 * frontend load-more trigger differs (classic page links vs. infinite scroll), left to the
 * UI part of this task (issue #74, part 4).
 */
enum PaginationMode: string
{
    case Classic = 'classic';
    case InfiniteScroll = 'infinite_scroll';
}
