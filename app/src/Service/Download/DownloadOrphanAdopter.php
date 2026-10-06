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

namespace App\Service\Download;

use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\Exception\QbittorrentClientException;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * "Link to entry": creates the `downloads` row for a torrent that already sits in the torrent
 * client without a card, and leaves finishing it to {@see DownloadCompletionPoller} (the row has
 * the same shape as after an enqueue or a move). The torrent itself is never touched: no
 * `torrents/add`, `setLocation` or `start`, and no tag is added. The storage is not chosen by the
 * user — {@see DownloadAdoptionPathClassifier} derives it from where the files are.
 *
 * Every check runs before the single write, so a refusal leaves the database as it was.
 */
class DownloadOrphanAdopter
{
    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly StorageRepository $storages,
        private readonly DownloadRepository $downloads,
        private readonly AnimeRepository $animes,
        private readonly StorageMarkerService $markerService,
        private readonly DownloadStorageFilesystem $storageFilesystem,
        private readonly DownloadAdoptionPathClassifier $classifier,
    ) {
    }

    /**
     * @return array<string, mixed>|null the torrent's `torrents/info` entry, null if the client does not hold it
     *
     * @throws QbittorrentClientException
     */
    public function findTorrent(string $infoHash): ?array
    {
        foreach ($this->client->getTorrentsInfo() as $torrent) {
            if (($torrent['infohash_v1'] ?? null) === $infoHash) {
                return $torrent;
            }
        }

        return null;
    }

    /**
     * @throws DownloadAdoptionRefusedException
     */
    public function adopt(string $infoHash, int $animeId): void
    {
        [$plan, $storage] = $this->resolvePlan($infoHash);

        $anime = $this->animes->findByIds([$animeId])[$animeId] ?? null;
        if ($anime === null) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_anime_not_found');
        }

        $this->assertNotLinked($infoHash);
        $this->assertFolderAvailable($anime, $storage, $plan);

        $this->save($infoHash, $anime, $storage);
    }

    /**
     * Where the torrent's files live right now, from a fresh `torrents/info`: the classifier's
     * plan and the storage it chose (its marker already verified).
     *
     * @return array{DownloadAdoptionPlan, Storage}
     *
     * @throws DownloadAdoptionRefusedException
     */
    public function resolvePlan(string $infoHash): array
    {
        try {
            $torrent = $this->findTorrent($infoHash);
        } catch (QbittorrentClientException) {
            throw new DownloadAdoptionRefusedException('download_new.error_client_unavailable');
        }
        if ($torrent === null) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_not_in_client');
        }

        $contentPath = (string) ($torrent['content_path'] ?? '');
        // Taken from the client, not the disk: an unfinished torrent may have no file at `content_path` yet.
        // Addressed by the client's own `hash` (a hybrid torrent is keyed by its truncated v2 hash), not by the stored v1.
        try {
            $fileNames = $this->client->getTorrentFileNames((string) ($torrent['hash'] ?? $infoHash));
        } catch (QbittorrentClientException) {
            throw new DownloadAdoptionRefusedException('download_new.error_client_unavailable');
        }
        $isSingleFile = \count($fileNames) === 1 && !str_contains($fileNames[0], '/') && !str_contains($fileNames[0], '\\');

        $storagesById = [];
        $candidates = [];
        foreach ($this->storages->findAllScannable() as $storage) {
            $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted.');
            $storagesById[$storageId] = $storage;
            $candidates[] = ['id' => $storageId, 'root' => $storage->getPath()];
        }

        $candidate = $this->classifier->selectStorage($contentPath, $candidates);
        // Only the chosen candidate's marker is read, and there is no fallback to an outer storage
        // when it does not match: a single file `<outer>\<nested>\<file>` would pass as
        // `<name>\<file>` of the outer storage and the poller would link the whole nested root.
        if ($this->markerService->readMarkerId($candidate['root']) !== $candidate['id']) {
            throw new DownloadAdoptionRefusedException('download_new.error_storage_unavailable');
        }
        $plan = $this->classifier->parse($contentPath, $infoHash, $candidate, $isSingleFile);

        return [$plan, $storagesById[$plan->storageId]];
    }

    /**
     * The folder `<root>\<name>` may go to $anime: it is free or already $anime's, $anime has no
     * other folder, and an incoming-branch move would not land on an existing path.
     *
     * @throws DownloadAdoptionRefusedException
     */
    public function assertFolderAvailable(Anime $anime, Storage $storage, DownloadAdoptionPlan $plan): void
    {
        $folderPath = rtrim($storage->getPath(), '\\/').'\\'.$plan->name;

        $owner = $this->animes->findByStorageAndPath($storage, $plan->name);
        if ($owner !== null && $owner->id !== $anime->id) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_folder_occupied', ['%path%' => $folderPath, '%id%' => (string) $owner->id]);
        }

        $ownStorage = $anime->getStorage();
        $ownPath = $anime->getStoragePath();
        if ($ownStorage !== null && $ownPath !== null && ($ownStorage->id !== $storage->id || $ownPath !== $plan->name)) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_anime_has_folder', ['%path%' => rtrim($ownStorage->getPath(), '\\/').'\\'.$ownPath]);
        }

        if ($plan->branch === DownloadAdoptionBranch::Incoming && $this->storageFilesystem->pathExists($folderPath)) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_incoming_target_exists', ['%path%' => $folderPath]);
        }
    }

    private function assertNotLinked(string $infoHash): void
    {
        $occupying = $this->downloads->findByInfoHash($infoHash)[0] ?? null;
        if ($occupying !== null) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_already_linked', ['%id%' => (string) $occupying->getAnime()->id]);
        }
    }

    private function save(string $infoHash, Anime $anime, Storage $storage): void
    {
        $download = new Download($infoHash, $anime);
        $download->assignTargetStorage($storage);

        // The UNIQUE index on info_hash is the real guard against a concurrent link of the same torrent.
        try {
            $this->downloads->save($download);
        } catch (UniqueConstraintViolationException $e) {
            $occupyingId = $this->downloads->findAnimeIdByInfoHash($infoHash);
            if ($occupyingId === null) {
                throw $e;
            }

            throw new DownloadAdoptionRefusedException('download_adopt.error_already_linked', ['%id%' => (string) $occupyingId]);
        }
    }
}
