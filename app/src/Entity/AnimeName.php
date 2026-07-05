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

use App\Entity\Enum\AnimeNameType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Physical table name is "anime_name" (singular), not "name", to avoid
 * ambiguity with the anime.title column and the generic word "name".
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_name')]
class AnimeName
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anime::class, inversedBy: 'names')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Column(length: 256)]
    public readonly string $name;

    #[ORM\Column(length: 16, enumType: AnimeNameType::class)]
    public readonly AnimeNameType $type;

    public function __construct(Anime $anime, string $name, AnimeNameType $type)
    {
        $this->anime = $anime;
        $this->name = $name;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
