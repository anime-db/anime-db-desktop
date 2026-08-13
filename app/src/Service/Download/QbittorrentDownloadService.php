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

use AnimeDb\PluginContracts\Download\DownloadServiceInterface;
use AnimeDb\PluginContracts\Download\DownloadSource;
use AnimeDb\PluginContracts\Download\DownloadSourceType;
use AnimeDb\PluginContracts\Download\DownloadTaskId;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Download;
use App\Repository\DownloadRepository;
use App\Service\Exception\InvalidTorrentFileException;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Core implementation of {@see DownloadServiceInterface} (issue #346) on top of qbittorrent-nox's
 * WebUI: qBittorrent is itself the durable store of a torrent's queue/progress/fast-resume data,
 * so enqueue() only ever (a) tells qBittorrent to start a NEW infoHash and (b) records the
 * (infoHash, anime) pairing this app cares about — it never builds a parallel download manager.
 *
 * DownloadTaskId is the torrent's infoHash, computed up front by TorrentInfoHashResolver rather
 * than read back from qBittorrent after adding it: that is what makes idempotency checkable
 * BEFORE any network call — see enqueue().
 */
final class QbittorrentDownloadService implements DownloadServiceInterface
{
    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly DownloadRepository $downloads,
        private readonly EntityManagerInterface $entityManager,
        private readonly DownloadFolderJail $jail,
        private readonly TorrentInfoHashResolver $infoHashResolver,
        private readonly FreeSpaceChecker $freeSpaceChecker,
    ) {
    }

    public function enqueue(DownloadSource $source, AnimeId $anime): DownloadTaskId
    {
        // Read once up front (not re-read in resolveInfoHash()/submitToQbittorrent() below):
        // a .torrent file is only ever needed for a Magnet-less source, and reading it once
        // avoids a second disk hit for the exact same bytes.
        $torrentFileContent = $source->type === DownloadSourceType::TorrentFile
            ? $this->readTorrentFile($source->value)
            : null;

        $infoHash = $this->resolveInfoHash($source, $torrentFileContent);

        // Already paired with this exact anime — nothing to do, return the same task id
        // (repeat enqueue() calls, e.g. a retried plugin action, must be a pure no-op).
        if ($this->downloads->findByInfoHashAndAnime($infoHash, $anime->value) !== null) {
            return new DownloadTaskId($infoHash);
        }

        // Known under a DIFFERENT anime already (season pack) — qBittorrent already has it,
        // only a new pairing row is needed, never a second download of the same infoHash.
        if (!$this->downloads->hasAnyForInfoHash($infoHash)) {
            $this->submitToQbittorrent($source, $infoHash, $torrentFileContent);
        }

        $animeReference = $this->entityManager->getReference(Anime::class, $anime->value)
            ?? throw new \LogicException(\sprintf('Anime #%d does not exist.', $anime->value));
        $this->downloads->save(new Download($infoHash, $animeReference));

        return new DownloadTaskId($infoHash);
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

    private function submitToQbittorrent(DownloadSource $source, string $infoHash, ?string $torrentFileContent): void
    {
        $savePath = $this->jail->resolveSavePathForInfoHash($infoHash);

        // A .torrent file's size is known up front — reject it here, before it is ever added to
        // qBittorrent (issue #348). A magnet's size is only known once qBittorrent has fetched
        // its metadata, so the equivalent check for it runs later, asynchronously, in
        // DownloadCompletionPoller.
        if ($source->type === DownloadSourceType::TorrentFile) {
            $this->freeSpaceChecker->assertEnoughSpaceForTorrentFile(
                $torrentFileContent ?? throw new \LogicException('Torrent file content must be read before checking its free space.'),
            );
        }

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
