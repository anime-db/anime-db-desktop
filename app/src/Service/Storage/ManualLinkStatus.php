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

namespace App\Service\Storage;

/** Why {@see ManualLinkService::link()} did or did not link; the steps are listed in the order they are checked. */
enum ManualLinkStatus
{
    case Linked;

    /** The selected path is not an absolute path. */
    case InvalidPath;

    /** A marker names a storage whose path in the database is different; the user must confirm the move. */
    case RelocateRequired;

    case OutsideStorages;

    /** The selected path is the storage root itself. */
    case StorageRoot;

    case ScanRunning;

    /** Several entries of the storage root differ from the selected name only by case. */
    case AmbiguousName;

    /** The top-level entry does not exist or cannot be read. */
    case EntryNotFound;

    /** The top-level entry exists but the scanner would not see it (hidden name, or a file that is not a video). */
    case EntryNotVisible;

    /** The (storage, top-level entry) pair already belongs to another entry. */
    case Occupied;
}
