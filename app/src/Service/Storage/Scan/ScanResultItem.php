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

namespace App\Service\Storage\Scan;

use App\Entity\Anime;

/**
 * One top-level storage file/folder from a single ScanStorageService::scan() run, tagged with
 * what happened to it. Which of $anime/$cleanedName/$candidates is populated depends on $type —
 * see the named constructors below for the exact combination each one carries.
 */
final class ScanResultItem
{
    /** @param list<ScanCandidate> $candidates */
    private function __construct(
        public readonly ScanItemType $type,
        public readonly string $storagePath,
        public readonly ?Anime $anime = null,
        public readonly ?string $cleanedName = null,
        public readonly array $candidates = [],
        public readonly ?string $alreadyLinkedStoragePath = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function updated(Anime $anime, string $storagePath): self
    {
        return new self(ScanItemType::Updated, $storagePath, anime: $anime);
    }

    public static function filesMissing(Anime $anime, string $storagePath): self
    {
        return new self(ScanItemType::FilesMissing, $storagePath, anime: $anime);
    }

    public static function autoLinked(Anime $anime, string $storagePath): self
    {
        return new self(ScanItemType::AutoLinked, $storagePath, anime: $anime);
    }

    public static function needsManualEntry(string $storagePath, string $cleanedName): self
    {
        return new self(ScanItemType::NeedsManualEntry, $storagePath, cleanedName: $cleanedName);
    }

    /** @param list<ScanCandidate> $candidates */
    public static function needsConfirmation(string $storagePath, string $cleanedName, array $candidates): self
    {
        return new self(ScanItemType::NeedsConfirmation, $storagePath, cleanedName: $cleanedName, candidates: $candidates);
    }

    /** $alreadyLinkedStoragePath is the path $anime is already linked to (not $storagePath). */
    public static function conflict(Anime $anime, string $storagePath, string $alreadyLinkedStoragePath): self
    {
        return new self(ScanItemType::Conflict, $storagePath, anime: $anime, alreadyLinkedStoragePath: $alreadyLinkedStoragePath);
    }

    public static function error(string $storagePath, string $cleanedName, string $errorMessage): self
    {
        return new self(ScanItemType::Error, $storagePath, cleanedName: $cleanedName, errorMessage: $errorMessage);
    }
}
