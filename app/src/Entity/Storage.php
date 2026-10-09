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

namespace App\Entity;

use App\Entity\Enum\StorageType;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidPathException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Storage
{
    /**
     * Windows drive-letter root ("D:\"), UNC share ("\\server\share"), or POSIX root
     * ("/..."). The shipped app is Windows-only, but the test suite runs on
     * ubuntu-latest CI (see .github/workflows/ci.yml) and exercises real filesystem
     * paths (e.g. sys_get_temp_dir()) — this only checks the root shape, not the
     * full path, and accepts both so tests keep working on either platform.
     */
    private const ABSOLUTE_PATH_PATTERN = '/^(?:[A-Za-z]:\\\\|\\\\\\\\[^\\\\]+\\\\[^\\\\]+|\/)/';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 256)]
    private string $name;

    #[ORM\Column(length: 16, enumType: StorageType::class)]
    private StorageType $type;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $dateUpdate = null;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $fileModified = null;

    public function __construct(string $name, ?string $path, StorageType $type)
    {
        $this->rename($name);
        $this->type = $type;
        $this->relocate($path);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidNameException('name must not be empty');
        }

        $this->name = $name;

        return $this;
    }

    public function getType(): StorageType
    {
        return $this->type;
    }

    /** Does not re-validate the path: change the type first, then {@see self::relocate()}, which checks it against the new type. */
    public function setType(StorageType $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** Null only for a type whose {@see StorageType::isPathRequired()} is false; always null for a type that is not {@see StorageType::isReadable()}. */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /** Whether a scan can run at all: the type is writable and a path is set. Single source of truth for the scan service, the scan button and the "Add" menu. */
    public function isScannable(): bool
    {
        return $this->type->isWritable() && $this->path !== null;
    }

    /** For code paths that only handle storages of a {@see StorageType::isPathRequired()} type, where a missing path is a broken invariant. */
    public function requirePath(): string
    {
        return $this->path ?? throw new \LogicException(\sprintf('Storage "%s" has no path.', $this->name));
    }

    /**
     * Only validates the path's shape (non-empty, absolute) — not that it exists on
     * disk right now. Existence is a point-in-time filesystem fact, not an entity
     * invariant: removable media can be offline while the Storage row stays valid
     * (see AnimeViewFactory::serializeStorage()'s path_available check), and a
     * relocate() must also work when reconnecting a storage whose drive letter
     * changed (desktop.ini marker match) before the new path has been confirmed reachable.
     */
    public function relocate(?string $path): self
    {
        if (!$this->type->isReadable()) {
            $this->path = null;

            return $this;
        }

        $path = $path === null ? '' : trim($path);
        if ($path === '' && !$this->type->isPathRequired()) {
            $this->path = null;

            return $this;
        }

        if ($path === '' || preg_match(self::ABSOLUTE_PATH_PATTERN, $path) !== 1) {
            throw new InvalidPathException(\sprintf('path must be an absolute Windows path, got "%s"', $path));
        }

        $this->path = $path;

        return $this;
    }

    public function getDateUpdate(): ?\DateTimeImmutable
    {
        return $this->dateUpdate;
    }

    public function getFileModified(): ?\DateTimeImmutable
    {
        return $this->fileModified;
    }

    /**
     * Records the result of a storage scan: dateUpdate is always "now" (when the scan
     * ran), fileModified is what the scanner observed on disk. A single method keeps
     * the two fields from drifting apart (e.g. updating one and forgetting the other).
     */
    public function markScanned(\DateTimeImmutable $fileModified): void
    {
        $this->dateUpdate = new \DateTimeImmutable();
        $this->fileModified = $fileModified;
    }
}
