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

use App\Entity\Enum\ThemeCode;
use Doctrine\ORM\Mapping as ORM;

/**
 * Row of the anime_themes join table. There is no standalone "theme" table:
 * the code list is a fixed dictionary enforced by ThemeCode + a DB CHECK constraint.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_themes')]
class AnimeTheme
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Anime::class, inversedBy: 'themes')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Id]
    #[ORM\Column(name: 'theme_code', length: 32, enumType: ThemeCode::class)]
    public readonly ThemeCode $code;

    public function __construct(Anime $anime, ThemeCode $code)
    {
        $this->anime = $anime;
        $this->code = $code;
    }
}
