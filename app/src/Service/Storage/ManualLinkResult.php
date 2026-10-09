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

use App\Entity\Anime;
use App\Entity\Storage;

/** Outcome of {@see ManualLinkService::link()}; which fields are set depends on {@see ManualLinkStatus}. */
final readonly class ManualLinkResult
{
    private function __construct(
        public ManualLinkStatus $status,
        public ?Storage $storage = null,
        public ?string $entryName = null,
        public bool $nested = false,
        public ?Anime $occupiedBy = null,
        public ?string $path = null,
    ) {
    }

    public static function linked(Storage $storage, string $entryName, bool $nested): self
    {
        return new self(ManualLinkStatus::Linked, $storage, $entryName, $nested);
    }

    /** $newPath is where the storage's marker was found, which differs from the path stored in the database. */
    public static function relocateRequired(Storage $storage, string $newPath): self
    {
        return new self(ManualLinkStatus::RelocateRequired, $storage, path: $newPath);
    }

    /** $suggestedStoragePath is the folder the selected entry lies in, to prefill the "create storage" form. */
    public static function outsideStorages(string $suggestedStoragePath): self
    {
        return new self(ManualLinkStatus::OutsideStorages, path: $suggestedStoragePath);
    }

    public static function occupied(Storage $storage, string $entryName, Anime $occupiedBy): self
    {
        return new self(ManualLinkStatus::Occupied, $storage, $entryName, occupiedBy: $occupiedBy);
    }

    public static function refused(ManualLinkStatus $status, ?Storage $storage = null, ?string $entryName = null): self
    {
        return new self($status, $storage, $entryName);
    }

    public function isLinked(): bool
    {
        return $this->status === ManualLinkStatus::Linked;
    }
}
