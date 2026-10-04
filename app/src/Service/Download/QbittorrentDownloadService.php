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

use AnimeDb\PluginContracts\Download\DownloadAlreadyLinkedToAnotherAnimeException;
use AnimeDb\PluginContracts\Download\DownloadServiceInterface;
use AnimeDb\PluginContracts\Download\DownloadSource;
use AnimeDb\PluginContracts\Download\DownloadSourceType;
use AnimeDb\PluginContracts\Download\DownloadTaskId;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Storage;
use App\Repository\DownloadRepository;
use App\Service\Exception\DownloadNotConfirmedException;
use App\Service\Exception\DownloadStorageNotWritableException;
use App\Service\Exception\DownloadStorageUnavailableException;
use App\Service\Exception\InvalidTorrentFileException;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Core implementation of {@see DownloadServiceInterface} (issue #346) on top of qbittorrent-nox's
 * WebUI: qBittorrent is itself the durable store of a torrent's queue/progress/fast-resume data,
 * so enqueueTo() only ever (a) tells qBittorrent to start a NEW infoHash and (b) records the
 * (infoHash, anime) pairing this app cares about — it never builds a parallel download manager.
 *
 * DownloadTaskId is the torrent's infoHash, computed up front by TorrentInfoHashResolver rather
 * than read back from qBittorrent after adding it: that is what makes idempotency checkable
 * BEFORE any network call — see enqueueTo().
 *
 * enqueue(), the {@see DownloadServiceInterface} contract method, keeps its original signature
 * (issue #851: no change to the read-only anime-db/plugin-contracts package) and simply calls
 * enqueueTo() with the lazily created preset Storage: a plugin calling through the contract has
 * no UI to pick a storage, so the choice has to be deterministic.
 */
class QbittorrentDownloadService implements DownloadServiceInterface
{
    /**
     * Same shape as {@see \App\Service\Plugin\PluginDirectoryRemover}: `torrents/add` answers
     * 200 even for some malformed input (see class docblock in the issue's "Детали"), so the
     * only proof a submitted torrent was actually accepted is finding it back in `torrents/info`
     * — polled a bounded number of times rather than trusted on the first miss, since qBittorrent
     * processing the add is not instantaneous.
     */
    private const int CONFIRMATION_MAX_ATTEMPTS = 5;
    private const int CONFIRMATION_RETRY_DELAY_MICROSECONDS = 200_000;

    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly DownloadRepository $downloads,
        private readonly EntityManagerInterface $entityManager,
        private readonly DownloadFolderJail $jail,
        private readonly TorrentInfoHashResolver $infoHashResolver,
        private readonly FreeSpaceChecker $freeSpaceChecker,
        private readonly PresetDownloadsStorageProvider $presetStorageProvider,
        private readonly StorageMarkerService $markerService,
        private readonly DownloadStorageFilesystem $storageFilesystem,
    ) {
    }

    public function enqueue(DownloadSource $source, AnimeId $anime): DownloadTaskId
    {
        return $this->enqueueTo($source, $anime, $this->presetStorageProvider->getOrCreate());
    }

    /**
     * @throws DownloadStorageNotWritableException if $storage's type cannot hold a download
     * @throws DownloadStorageUnavailableException if $storage's path does not exist, or its
     *                                             desktop.ini marker does not name $storage
     * @throws DownloadNotConfirmedException       if qBittorrent never reports the submitted
     *                                             torrent back within the confirmation retries
     */
    public function enqueueTo(DownloadSource $source, AnimeId $anime, Storage $storage): DownloadTaskId
    {
        if (!$storage->getType()->isWritable()) {
            throw new DownloadStorageNotWritableException($storage->getType());
        }

        // Read once up front (not re-read in resolveInfoHash()/submitToQbittorrent() below):
        // a .torrent file is only ever needed for a Magnet-less source, and reading it once
        // avoids a second disk hit for the exact same bytes.
        $torrentFileContent = $source->type === DownloadSourceType::TorrentFile
            ? $this->readTorrentFile($source->value)
            : null;

        // Rejected here, before resolveInfoHash() hashes it and before any database access
        // (issue #843): a v2-only torrent has no v1 info hash, which is the identity this app
        // relies on throughout (see class docblock), so there is nothing meaningful to hash it
        // into. Checked first since downstream code assumes a usable v1 identity already exists.
        if ($torrentFileContent !== null && $this->infoHashResolver->isV2Only($torrentFileContent)) {
            throw new InvalidTorrentFileException(\sprintf('Torrent file "%s" is BitTorrent v2-only (it has no v1 info hash); v2-only torrents are not supported.', basename($source->value)));
        }

        $infoHash = $this->resolveInfoHash($source, $torrentFileContent);

        // Already paired with this exact anime — nothing to do, return the same task id
        // (repeat enqueue() calls, e.g. a retried plugin action, must be a pure no-op).
        if ($this->downloads->findByInfoHashAndAnime($infoHash, $anime->value) !== null) {
            return new DownloadTaskId($infoHash);
        }

        // Already linked to a DIFFERENT anime — one torrent folder must not back two anime records.
        $occupying = $this->downloads->findByInfoHash($infoHash)[0] ?? null;
        if ($occupying !== null) {
            throw new DownloadAlreadyLinkedToAnotherAnimeException($infoHash, new AnimeId((int) $occupying->getAnime()->id));
        }

        $this->assertStorageAvailable($storage);
        $this->submitToQbittorrent($source, $storage, $infoHash, $torrentFileContent);
        $this->confirmSubmitted($infoHash);

        $animeReference = $this->entityManager->getReference(Anime::class, $anime->value)
            ?? throw new \LogicException(\sprintf('Anime #%d does not exist.', $anime->value));

        $download = new Download($infoHash, $animeReference);
        $download->assignTargetStorage($storage);

        // The UNIQUE index on info_hash is the real guard: a concurrent enqueue() for another anime
        // passes the check above too, and only one of the two saves can win.
        try {
            $this->downloads->save($download);
        } catch (UniqueConstraintViolationException $e) {
            $occupyingId = $this->downloads->findAnimeIdByInfoHash($infoHash);
            if ($occupyingId === $anime->value) {
                // The same anime raced us: an idempotent repeat, not a conflict.
                return new DownloadTaskId($infoHash);
            }
            if ($occupyingId === null) {
                throw $e;
            }

            throw new DownloadAlreadyLinkedToAnotherAnimeException($infoHash, new AnimeId($occupyingId));
        }

        return new DownloadTaskId($infoHash);
    }

    /**
     * @throws DownloadStorageUnavailableException if $storage's path is missing or its marker
     *                                             does not name $storage
     */
    private function assertStorageAvailable(Storage $storage): void
    {
        $path = $storage->getPath();
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before a download can target it.');

        if (!$this->storageFilesystem->pathExists($path) || $this->markerService->readMarkerId($path) !== $storageId) {
            throw new DownloadStorageUnavailableException($storageId, $path);
        }
    }

    /**
     * Polls `torrents/info` for $infoHash, the only proof qBittorrent actually accepted the
     * torrent just submitted (see class docblock) — matched on `infohash_v1` just like
     * {@see DownloadCompletionPoller::fetchTorrentsByInfoHashV1()}, for the same hybrid-torrent
     * reason documented there.
     *
     * @throws DownloadNotConfirmedException if $infoHash never shows up within the retries
     */
    private function confirmSubmitted(string $infoHash): void
    {
        for ($attempt = 1; $attempt <= self::CONFIRMATION_MAX_ATTEMPTS; ++$attempt) {
            foreach ($this->client->getTorrentsInfo(QbittorrentClient::TAG) as $torrent) {
                if (($torrent['infohash_v1'] ?? null) === $infoHash) {
                    return;
                }
            }

            if ($attempt < self::CONFIRMATION_MAX_ATTEMPTS) {
                usleep(self::CONFIRMATION_RETRY_DELAY_MICROSECONDS);
            }
        }

        throw new DownloadNotConfirmedException($infoHash);
    }

    private function resolveInfoHash(DownloadSource $source, ?string $torrentFileContent): string
    {
        return match ($source->type) {
            DownloadSourceType::Magnet => $this->infoHashResolver->fromMagnet($source->value),
            DownloadSourceType::TorrentFile => $this->infoHashResolver->fromTorrentFileContent(
                $torrentFileContent ?? throw new \LogicException('Torrent file content must be read before resolving its infoHash.'),
            ),
        };
    }

    private function submitToQbittorrent(DownloadSource $source, Storage $storage, string $infoHash, ?string $torrentFileContent): void
    {
        $storageRoot = $storage->getPath();
        $savePath = $this->jail->resolveIncomingSavePathForInfoHash($storageRoot, $infoHash);

        // A .torrent file's size is known up front — reject it here, before it is ever added to
        // qBittorrent (issue #348). A magnet's size is only known once qBittorrent has fetched
        // its metadata, so the equivalent check for it runs later, asynchronously, in
        // DownloadCompletionPoller.
        if ($source->type === DownloadSourceType::TorrentFile) {
            $this->freeSpaceChecker->assertEnoughSpaceForTorrentFile(
                $torrentFileContent ?? throw new \LogicException('Torrent file content must be read before checking its free space.'),
                $storageRoot,
            );
        }

        // Created and hidden before qBittorrent ever sees a save-path under it (issue #851): a
        // download in progress must not surface as a top-level entry in the storage scanner
        // while it is still incoming.
        $this->storageFilesystem->ensureHiddenDirectoryExists($this->jail->incomingRoot($storageRoot));

        match ($source->type) {
            DownloadSourceType::Magnet => $this->client->addTorrentFromMagnet($source->value, $savePath),
            DownloadSourceType::TorrentFile => $this->client->addTorrentFromFile(
                basename($source->value),
                $torrentFileContent ?? throw new \LogicException('Torrent file content must be read before submitting it.'),
                $savePath,
            ),
        };
    }

    private function readTorrentFile(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidTorrentFileException(\sprintf('Unable to read torrent file "%s".', $path));
        }

        return $content;
    }
}
