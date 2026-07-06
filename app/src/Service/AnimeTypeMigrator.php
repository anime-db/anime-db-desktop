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

namespace App\Service;

use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Infrastructure side of an Anime type migration: the actual field-copying and validation
 * live on Anime::migrate() (domain logic belongs on the entity, not here), so migration is
 * driven from the source entity itself ($source->migrate($targetType)) rather than a static
 * factory call; this class only wires that up to Doctrine.
 */
final class AnimeTypeMigrator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param AnimeType $targetType must differ from $source->getType()
     */
    public function migrate(Anime $source, AnimeType $targetType): Anime
    {
        $target = $source->migrate($targetType);

        // Persist the copy (and its cascaded genres/names/images/sources) before removing
        // the source, so the ON DELETE CASCADE on anime_id never fires against data we
        // still need.
        $this->entityManager->persist($target);
        $this->entityManager->remove($source);
        $this->entityManager->flush();

        return $target;
    }
}
