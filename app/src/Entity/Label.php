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

#[ORM\Entity]
class Label
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    public private(set) string $name;

    /** @var Collection<int, Anime> */
    #[ORM\ManyToMany(targetEntity: Anime::class, mappedBy: 'labels')]
    private Collection $animes;

    public function __construct()
    {
        $this->animes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
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
     * Inverse-side sync only, called from Anime::addLabel(). Not meant to be called directly.
     */
    public function addAnime(Anime $anime): self
    {
        if (!$this->animes->contains($anime)) {
            $this->animes->add($anime);
        }

        return $this;
    }

    /**
     * Inverse-side sync only, called from Anime::removeLabel(). Not meant to be called directly.
     */
    public function removeAnime(Anime $anime): self
    {
        $this->animes->removeElement($anime);

        return $this;
    }
}
