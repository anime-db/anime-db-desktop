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

    /**
     * Plain DBAL lookup on purpose: it stays usable after a failed flush() has closed the
     * EntityManager (see QbittorrentDownloadService::enqueue()).
     */
    public function findAnimeIdByInfoHash(string $infoHash): ?int
    {
        $animeId = $this->entityManager->getConnection()->fetchOne(
            'SELECT anime_id FROM downloads WHERE info_hash = ?',
            [$infoHash],
        );

        return $animeId === false ? null : (int) $animeId;
    }

    /** @return list<Download> */
    public function findByInfoHash(string $infoHash): array
    {
        return $this->entityManager->getRepository(Download::class)->findBy(['infoHash' => $infoHash]);
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

    /** Removes only the pairing row; the anime, its storage and the torrent itself are untouched. */
    public function remove(Download $download): void
    {
        $this->entityManager->remove($download);
        $this->entityManager->flush();
    }

    /**
     * Whether $storageId has a row still in progress (status Pending) with it as $targetStorage —
     * the guard StorageController::delete() and StorageEditController::update() (issue #853)
     * consult before letting a storage's path change or the storage itself disappear out from
     * under a download qBittorrent is still writing.
     *
     * Failed is excluded on purpose: it is terminal ({@see Download::markFailed()} only leaves
     * Pending, and nothing transitions a row back out of Failed), so a storage that once had a
     * failed download would otherwise stay un-deletable/un-relocatable forever even once nothing
     * is writing into it.
     */
    public function hasUnfinishedDownloadsForTargetStorage(int $storageId): bool
    {
        $count = $this->entityManager->getRepository(Download::class)->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.targetStorage = :storageId')
            ->andWhere('d.status = :pending')
            ->setParameter('storageId', $storageId)
            ->setParameter('pending', DownloadStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
