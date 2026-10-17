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

namespace App\Service\Folder;

use AnimeDb\PluginContracts\Folder\RecordFolderIndexInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enumerates the catalog records that have a folder of their own (issue #1075): a storage set, a
 * non-empty path in it, and a storage type that allows writing. One shared service — it keeps
 * nothing per plugin — handed to `local` plugins only by
 * {@see \App\Service\Plugin\DependencyInjection\Compiler\RecordFolderWriterScopePass}.
 *
 * Database only, no disk access and no availability filter: whether a storage is reachable is
 * checked per record by {@see RecordFolderWriter}.
 */
final class RecordFolderIndex implements RecordFolderIndexInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function recordsWithFolder(?AnimeId $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }

        $writable = array_values(array_filter(StorageType::cases(), static fn (StorageType $type): bool => $type->isWritable()));

        $ids = $this->entityManager->createQueryBuilder()
            ->select('a.id')
            ->from(Anime::class, 'a')
            ->innerJoin('a.storage', 's')
            ->andWhere('s.type IN (:types)')
            ->andWhere("a.storagePath IS NOT NULL AND TRIM(a.storagePath) <> ''")
            ->andWhere('a.id > :after')
            ->setParameter('types', $writable)
            ->setParameter('after', $after->value ?? 0)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): AnimeId => new AnimeId((int) $id), $ids));
    }
}
