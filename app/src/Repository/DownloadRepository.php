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

    /**
     * Every row, newest first — the "Downloads" page's own listing (issue #854).
     *
     * @return list<Download>
     */
    public function findAllOrderedByDateAddDesc(): array
    {
        return $this->entityManager->getRepository(Download::class)->findBy([], ['dateAdd' => 'DESC']);
    }

    /**
     * All pairing rows of a single anime entry — the "Downloads for this entry" block on its page.
     *
     * @return list<Download>
     */
    public function findByAnime(int $animeId): array
    {
        return $this->entityManager->getRepository(Download::class)->findBy(['anime' => $animeId]);
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
     * Whether $storageId has a row that is not yet Completed with it as $targetStorage —
     * the guard StorageController::delete() and StorageEditController::update() (issue #853)
     * consult before letting a storage's path change or the storage itself disappear out from
     * under a download qBittorrent is still writing or still holding torrent data for.
     *
     * Failed is included on purpose even though it is currently terminal: a Failed row's torrent
     * still sits in qBittorrent with data under this storage's path, and issue #856's planned
     * retry() will move some Failed rows back to Pending, which would break if the storage (or
     * its target_storage_id, cleared via ON DELETE SET NULL) had already been removed or
     * relocated out from under it. A storage only stops being blocked once its Failed rows are
     * removed by the user.
     */
    public function hasUnfinishedDownloadsForTargetStorage(int $storageId): bool
    {
        $count = $this->entityManager->getRepository(Download::class)->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.targetStorage = :storageId')
            ->andWhere('d.status != :completed')
            ->setParameter('storageId', $storageId)
            ->setParameter('completed', DownloadStatus::Completed)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
