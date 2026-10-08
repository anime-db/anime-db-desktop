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

namespace App\Entity\Enum;

/**
 * Kind of physical medium a {@see \App\Entity\Storage} stands for. Two independent axes describe it —
 * writability ({@see self::isWritable()}) and readability ({@see self::isReadable()}); readability
 * is not the inverse of writability. The path requirement follows from them ({@see self::isPathRequired()}).
 *
 * | Type       | Medium                                     | Writable | Readable | Path           |
 * |------------|--------------------------------------------|----------|----------|----------------|
 * | folder     | local or network folder                    | yes      | yes      | required       |
 * | external   | external drive: HDD, flash drive, SD card  | yes      | yes      | required       |
 * | external-r | recorded data disc: CD-R, CD-RW, DVD-R, DVD-RW | no  | yes      | optional       |
 * | video      | video media: DVD, BD, VHS                  | no       | no       | not applicable |
 */
enum StorageType: string
{
    /** Folder on the computer, local or network. Writable, readable, path required. */
    case Folder = 'folder';

    /** External drive: HDD, flash drive, SD card. Writable, readable, path required. */
    case External = 'external';

    /**
     * Recorded data disc: CD-R, CD-RW, DVD-R, DVD-RW. Not writable, readable; the path is optional
     * because the disc may not be inserted and requiring a drive letter makes no sense.
     */
    case ExternalR = 'external-r';

    /**
     * Video media: DVD, BD, VHS. It is not a file medium: neither writable nor readable by the
     * application, so a path is not applicable.
     */
    case Video = 'video';

    /** Whether the storage type allows writing a desktop.ini marker and can be scanned for files. */
    public function isWritable(): bool
    {
        return match ($this) {
            self::Folder, self::External => true,
            self::ExternalR, self::Video => false,
        };
    }

    /** Whether the application can read files from the medium. Independent of {@see self::isWritable()}. */
    public function isReadable(): bool
    {
        return match ($this) {
            self::Folder, self::External, self::ExternalR => true,
            self::Video => false,
        };
    }

    /** Whether a storage of this type must have a path: required for writable types, optional or not applicable otherwise. */
    public function isPathRequired(): bool
    {
        return $this->isWritable();
    }
}
