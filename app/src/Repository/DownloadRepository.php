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

namespace App\Repository;

use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use Doctrine\ORM\EntityManagerInterface;

class DownloadRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findByInfoHashAndAnime(string $infoHash, int $animeId): ?Download
    {
        return $this->entityManager->getRepository(Download::class)->findOneBy([
            'infoHash' => $infoHash,
            'anime' => $animeId,
        ]);
    }

    /** @return list<Download> */
    public function findByInfoHash(string $infoHash): array
    {
        return $this->entityManager->getRepository(Download::class)->findBy(['infoHash' => $infoHash]);
    }

    public function hasAnyForInfoHash(string $infoHash): bool
    {
        return $this->findByInfoHash($infoHash) !== [];
    }

    /** @return list<Download> */
    public function findPendingByInfoHash(string $infoHash): array
    {
        return $this->entityManager->getRepository(Download::class)->findBy([
            'infoHash' => $infoHash,
            'status' => DownloadStatus::Pending,
        ]);
    }

    /**
     * Distinct infoHashes with at least one row still Pending — the set
     * DownloadCompletionPoller::poll() needs to ask qBittorrent about on each run.
     *
     * @return list<string>
     */
    public function findDistinctPendingInfoHashes(): array
    {
        $rows = $this->entityManager->getRepository(Download::class)->createQueryBuilder('d')
            ->select('DISTINCT d.infoHash AS infoHash')
            ->where('d.status = :status')
            ->setParameter('status', DownloadStatus::Pending)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'infoHash');
    }

    public function save(Download $download): void
    {
        $this->entityManager->persist($download);
        $this->entityManager->flush();
    }
}
