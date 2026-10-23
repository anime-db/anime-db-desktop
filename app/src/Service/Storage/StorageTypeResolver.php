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

use App\Entity\Enum\StorageType;

/**
 * Suggests a StorageType for a path by the volume it lies on (never by the path itself).
 *
 * Only the drive letter taken by {@see self::driveLetter()} may reach the volume probe: the path
 * is neither stat()-ed nor read nor put on a command line. A UNC path is classified from the
 * string alone, with no access to the host. StorageType::Video is never suggested.
 */
final class StorageTypeResolver
{
    private const string DRIVE_LETTER_PATTERN = '~^([A-Za-z]):[\\\\/]~';
    private const string UNC_PATTERN = '~^[\\\\/]{2}~';

    /** Disk buses that are removable media. */
    private const array REMOVABLE_BUSES = ['usb', 'sd', 'mmc', '1394'];

    /** Known buses of a built-in or network-like disk. */
    private const array FIXED_BUSES = [
        'sata', 'nvme', 'raid', 'sas', 'spaces', 'filebackedvirtual', 'iscsi', 'virtual',
        'scsi', 'ata', 'atapi', 'fibre channel', 'ssa', 'scm', 'ufs',
    ];

    public function __construct(
        private readonly VolumeProbeInterface $volumeProbe,
    ) {
    }

    /** The drive letter of a `D:\...` path, or null for any other path. */
    public function driveLetter(string $path): ?string
    {
        return preg_match(self::DRIVE_LETTER_PATTERN, trim($path), $matches) === 1 ? strtoupper($matches[1]) : null;
    }

    /** The type known from the string alone (a UNC path), without touching the volume. */
    public function typeFromString(string $path): ?StorageType
    {
        return preg_match(self::UNC_PATTERN, trim($path)) === 1 ? StorageType::Folder : null;
    }

    /** Probes the volume of a path with a drive letter (one probe run); null for any other path. */
    public function probe(string $path): ?VolumeInfo
    {
        $letter = $this->driveLetter($path);

        return $letter === null ? null : $this->volumeProbe->probe($letter);
    }

    /** @param VolumeInfo|null $volume the result of {@see self::probe()} for the same path */
    public function resolve(string $path, ?VolumeInfo $volume): ?StorageType
    {
        $fromString = $this->typeFromString($path);
        if ($fromString !== null) {
            return $fromString;
        }

        if ($volume === null || $this->driveLetter($path) === null) {
            return null;
        }

        return match (strtolower(trim((string) $volume->driveType))) {
            'cdrom' => StorageType::ExternalR,
            'removable' => StorageType::External,
            'network' => StorageType::Folder,
            'fixed' => $this->byBus($volume->busType),
            default => null,
        };
    }

    private function byBus(?string $busType): ?StorageType
    {
        $bus = strtolower(trim((string) $busType));

        return match (true) {
            \in_array($bus, self::REMOVABLE_BUSES, true) => StorageType::External,
            \in_array($bus, self::FIXED_BUSES, true) => StorageType::Folder,
            default => null,
        };
    }
}
