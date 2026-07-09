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

namespace App\Service\Storage;

use App\Entity\Anime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds Anime records that could match a cleaned scan filename (see FilenameCleaner)
 * even though no plugin recognized it: catalog entries added manually by the user, or
 * synced by a plugin without ever getting a physical file attached ("orphans"). This is
 * a separate candidate source from the plugin chain (Таск 3 часть 4) and knows nothing
 * about it — the scanner combines both lists on its own.
 *
 * Only Anime with neither $storage nor $storagePath set are considered orphans: either
 * one being set already means the row is linked to a file and must not be offered again.
 *
 * Matching is intentionally simple (case/whitespace-insensitive exact comparison), not a
 * weighted fuzzy search — the same approach v1 used when matching by basename.
 */
final class OrphanAnimeMatcher
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<Anime> */
    public function findCandidates(string $cleanedName): array
    {
        $needle = $this->normalize($cleanedName);

        /** @var list<Anime> $orphans */
        $orphans = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->andWhere('a.storage IS NULL')
            ->andWhere('a.storagePath IS NULL')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $orphans,
            fn (Anime $anime): bool => $this->matches($anime, $needle),
        ));
    }

    private function matches(Anime $anime, string $needle): bool
    {
        if ($this->normalize($anime->getTitle()) === $needle) {
            return true;
        }

        foreach ($anime->getNames() as $name) {
            if ($this->normalize($name->name) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }
}
