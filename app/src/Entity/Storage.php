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

namespace App\Entity;

use App\Entity\Enum\StorageType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Storage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 256)]
    private string $name;

    #[ORM\Column(length: 16, enumType: StorageType::class)]
    private StorageType $type;

    #[ORM\Column(length: 1024)]
    private string $path;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $dateUpdate = null;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $fileModified = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): StorageType
    {
        return $this->type;
    }

    public function setType(StorageType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getDateUpdate(): ?\DateTimeImmutable
    {
        return $this->dateUpdate;
    }

    public function setDateUpdate(?\DateTimeImmutable $dateUpdate): self
    {
        $this->dateUpdate = $dateUpdate;

        return $this;
    }

    public function getFileModified(): ?\DateTimeImmutable
    {
        return $this->fileModified;
    }

    public function setFileModified(?\DateTimeImmutable $fileModified): self
    {
        $this->fileModified = $fileModified;

        return $this;
    }
}
