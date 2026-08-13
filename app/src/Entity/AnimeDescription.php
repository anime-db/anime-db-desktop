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

use Doctrine\ORM\Mapping as ORM;

/**
 * One locale's summary text for an anime (issue #298). Split out of the anime.metadata
 * JSON blob into its own table so a catalog list query (AnimeRepository::findByFilter())
 * never has to load every locale's description text for every row — Doctrine only issues
 * a query against this table when Anime::getSummary()/getDescriptions() is actually
 * accessed, which today is only on the anime detail page (AnimeViewFactory::serialize()).
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_description')]
#[ORM\UniqueConstraint(name: 'UNIQ_ANIME_DESCRIPTION_ANIME_LOCALE', columns: ['anime_id', 'locale'])]
class AnimeDescription
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anime::class, inversedBy: 'descriptions')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Column(length: 8)]
    public readonly string $locale;

    #[ORM\Column(type: 'text')]
    public string $description;

    public function __construct(Anime $anime, string $locale, string $description)
    {
        $this->anime = $anime;
        $this->locale = $locale;
        $this->description = $description;
    }
}
