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

use App\Entity\Enum\AnimeNameRole;
use Doctrine\ORM\Mapping as ORM;

/**
 * Physical table name is "anime_name" (singular), not "name", to avoid
 * ambiguity with the anime.title column and the generic word "name".
 *
 * Carries language and role as two independent axes (issue #724) rather than the single
 * mixed AnimeNameType enum it replaces: $locale says what language the name is in (or null,
 * a normal value for an untyped synonym), $role says what the name is for (official title,
 * synonym, short form) — an official title and a synonym can both exist in the same locale.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_name')]
#[ORM\Index(name: 'IDX_ANIME_NAME_ANIME', fields: ['anime'])]
#[ORM\Index(name: 'IDX_ANIME_NAME_NORMALIZED_NAME', fields: ['normalizedName'])]
class AnimeName
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anime::class, inversedBy: 'names')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Column(length: 256)]
    public readonly string $name;

    /** NameNormalizer::normalize($name), computed once here since $name is readonly. */
    #[ORM\Column(length: 256)]
    public readonly string $normalizedName;

    /** LocaleNormalizer::normalize($locale), computed once here since $locale is readonly. */
    #[ORM\Column(length: 16, nullable: true)]
    public readonly ?string $locale;

    #[ORM\Column(length: 16, enumType: AnimeNameRole::class)]
    public readonly AnimeNameRole $role;

    public function __construct(Anime $anime, string $name, ?string $locale, AnimeNameRole $role)
    {
        $this->anime = $anime;
        $this->name = $name;
        $this->normalizedName = NameNormalizer::normalize($name);
        $this->locale = LocaleNormalizer::normalize($locale);
        $this->role = $role;
    }
}
