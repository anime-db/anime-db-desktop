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

use App\Entity\Storage;
use App\Service\Path\LexicalPathNormalizer;

/**
 * Outcome of {@see StorageLocator::locate()}; which fields are set depends on {@see StorageLocationKind}.
 *
 * For a found storage $rootPath is the directory the storage root is at: the one stored in the
 * database, or — when $relocated is true — the one where the storage's marker was found instead.
 */
final readonly class StorageLocation
{
    private function __construct(
        public StorageLocationKind $kind,
        public ?Storage $storage = null,
        public ?string $rootPath = null,
        public bool $relocated = false,
        public ?string $path = null,
        public ?string $suggestedStoragePath = null,
    ) {
    }

    public static function invalidPath(): self
    {
        return new self(StorageLocationKind::InvalidPath);
    }

    /**
     * $suggestedStoragePath is the folder the selected entry lies in, to prefill the "create storage" form.
     * It is meant for the manual link flow, where the selected entry is an item inside a folder; callers that
     * select a folder itself (e.g. "Open in AnimeDB") suggest that folder instead.
     */
    public static function outside(string $suggestedStoragePath): self
    {
        return new self(StorageLocationKind::Outside, suggestedStoragePath: $suggestedStoragePath);
    }

    /** $path is the normalized selected path. */
    public static function found(Storage $storage, string $rootPath, bool $relocated, string $path): self
    {
        return new self(StorageLocationKind::Found, $storage, $rootPath, $relocated, $path);
    }

    public function normalizedRoot(): string
    {
        return LexicalPathNormalizer::normalize($this->rootPath ?? '');
    }

    /** Whether the selected path is the storage root itself. */
    public function isRoot(): bool
    {
        return $this->path !== null && mb_strtolower($this->path) === mb_strtolower($this->normalizedRoot());
    }

    /** Whether the selected path lies within the storage root (the root itself included). */
    public function isWithinRoot(): bool
    {
        return $this->path !== null && LexicalPathNormalizer::isWithin($this->normalizedRoot(), $this->path);
    }

    /** The name of the top-level item of the root the selected path lies in, or null for the root itself. */
    public function topLevelName(): ?string
    {
        if ($this->path === null || !$this->isWithinRoot() || $this->isRoot()) {
            return null;
        }

        $relative = ltrim(substr($this->path, \strlen($this->normalizedRoot())), '\\');

        return explode('\\', $relative, 2)[0];
    }
}
