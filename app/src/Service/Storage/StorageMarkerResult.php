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

namespace App\Service\Storage;

enum StorageMarkerResult
{
    /** No marker was present at the path; it now carries this Storage's id. */
    case Created;

    /** The marker already carried this Storage's own id. */
    case Owned;

    /** The marker carried the id of a Storage that no longer exists; it was rewritten. */
    case Reclaimed;

    /** The marker carries the id of a different, still-existing Storage; the path was left untouched. */
    case Conflict;
}
