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

use App\Entity\Exception\InvalidNameException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Filled only by application code (plugins/sync), not editable by the user directly.
 */
#[ORM\Entity]
class Studio
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 256)]
    public private(set) string $name;

    /** @var Collection<int, Anime> */
    #[ORM\ManyToMany(targetEntity: Anime::class, mappedBy: 'studios')]
    private Collection $animes;

    public function __construct()
    {
        $this->animes = new ArrayCollection();
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidNameException('name must not be empty');
        }

        $this->name = $name;
    }

    /** @return Collection<int, Anime> */
    public function getAnimes(): Collection
    {
        return $this->animes;
    }

    /**
     * Inverse-side sync only, called from Anime::addStudio(). Not meant to be called directly.
     */
    public function addAnime(Anime $anime): self
    {
        if (!$this->animes->contains($anime)) {
            $this->animes->add($anime);
        }

        return $this;
    }

    /**
     * Inverse-side sync only, called from Anime::removeStudio(). Not meant to be called directly.
     */
    public function removeAnime(Anime $anime): self
    {
        $this->animes->removeElement($anime);

        return $this;
    }

    /**
     * Must be checked before deleting a Studio: the DB enforces ON DELETE RESTRICT
     * on anime_studios.studio_id, so removing a studio still linked to an Anime
     * throws a raw FK-violation exception instead of a user-friendly error.
     */
    public function isRemovable(): bool
    {
        return $this->animes->isEmpty();
    }
}
