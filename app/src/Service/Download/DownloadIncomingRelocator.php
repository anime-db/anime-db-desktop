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

use App\Entity\Download;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves a finished download out of a storage's hidden incoming directory
 * (`<storage>\.anime-db\incoming\<infoHash>`) into the storage root itself, via qBittorrent's own
 * `torrents/setLocation` (issue #852) — never by touching files on disk directly (see
 * {@see QbittorrentClient::setSavePath()}).
 *
 * This is deliberately NOT done with qBittorrent's built-in `useDownloadPath` move-on-complete:
 * `torrents/setLocation` onto an already-occupied name does not fail, it silently MERGES the two
 * folders (libtorrent's `dont_replace`, see the issue's "Проблема") and answers 200 either way.
 * {@see self::tryMove()} therefore always checks for a conflict — both on disk (via
 * {@see DownloadStorageFilesystem}, since a real path like "E:\..." does not exist on the Linux CI
 * runner this test suite runs on) and in the database (via
 * {@see AnimeRepository::findByStorageAndPath()}, since another Anime's storage_path can occupy a
 * name even when nothing currently sits there on disk) — strictly BEFORE ever calling
 * `torrents/setLocation`, never after.
 *
 * There is no "move requested" flag: `torrents/setLocation` answers immediately while qBittorrent
 * moves the files asynchronously, so content_path reported by the next `torrents/info` IS the only
 * source of truth for whether the move actually happened — DownloadCompletionPoller re-evaluates
 * it on every poll pass and calls {@see self::tryMove()} again if it still reports a path under
 * incoming. $moveAttempts (issue #852) bounds that retry: once it reaches {@see
 * self::MAX_MOVE_ATTEMPTS} with the torrent still under incoming, the row is failed for good
 * instead of retrying forever against whatever is blocking the move (e.g. a locked file).
 */
final class DownloadIncomingRelocator
{
    /**
     * How many `torrents/setLocation` requests {@see self::tryMove()} sends before giving up and
     * failing the row with `move_failed` — a bound, not a tunable setting, same reasoning as
     * {@see FreeSpaceChecker}'s overhead constants.
     */
    private const int MAX_MOVE_ATTEMPTS = 3;

    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly AnimeRepository $animes,
        private readonly StorageMarkerService $markerService,
        private readonly DownloadStorageFilesystem $storageFilesystem,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether $storage's own desktop.ini marker still names $storage (issue #852) — checked both
     * before a move out of incoming and before linking an already-moved folder, since a storage's
     * path can be reassigned (or simply become unreachable, e.g. an unplugged drive) between the
     * download being enqueued and it finishing. A mismatch is not a failure: the caller leaves the
     * row Pending untouched, surfaced on the "Downloads" page as "storage unavailable" (issue
     * #854) rather than spent as one of $moveAttempts.
     */
    public function isStorageMarkerValid(Storage $storage): bool
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its marker can be checked.');

        return $this->markerService->readMarkerId($storage->getPath()) === $storageId;
    }

    /**
     * @param array<string, mixed> $torrent the qBittorrent torrent backing $download, as returned
     *                                      by `torrents/info` — read for its "hash" (qBittorrent's
     *                                      own torrent id, see {@see
     *                                      DownloadCompletionPoller::fetchTorrentsByInfoHashV1()}
     *                                      for why that is not this app's v1 infoHash)
     */
    public function tryMove(Download $download, Storage $storage, string $resolvedContentPath, array $torrent, string $infoHash): void
    {
        $storageRoot = rtrim($storage->getPath(), '\\/');

        // basename() of content_path, NOT the torrent's own "name" field: libtorrent already
        // sanitized it for the filesystem (stripped characters Windows rejects, etc.), and a
        // single-file torrent's content_path is the file itself, not a folder sharing the
        // torrent's name (see class docblock's "Проблема").
        $targetName = self::basename($resolvedContentPath);
        $isSingleFile = $this->storageFilesystem->isFile($resolvedContentPath);
        // A single file must not land bare in the storage root (an unrecognized extension like
        // ".mka"/".iso" would be filtered out by the storage scanner) — it gets its own folder,
        // named after the file without its extension, same as every other entry in the root.
        $folderName = $isSingleFile ? self::withoutExtension($targetName) : $targetName;
        $targetPath = $storageRoot.'\\'.$folderName;

        if ($this->storageFilesystem->pathExists($targetPath) || $this->animes->findByStorageAndPath($storage, $folderName) !== null) {
            $download->markFailed('name_conflict');
            $this->entityManager->flush();
            $this->logger->warning('Failing download completion: the move target is already occupied (on disk or by another catalog entry).', [
                'infoHash' => $infoHash,
                'targetPath' => $targetPath,
            ]);

            return;
        }

        if ($download->getMoveAttempts() >= self::MAX_MOVE_ATTEMPTS) {
            $download->markFailed('move_failed');
            $this->entityManager->flush();
            $this->logger->warning('Failing download completion: it is still under the incoming directory after the maximum number of move attempts.', [
                'infoHash' => $infoHash,
                'attempts' => $download->getMoveAttempts(),
            ]);

            return;
        }

        // The parent qBittorrent should move the torrent's data INTO — the storage root for a
        // multi-file torrent (its content_path already names the right top-level folder), or the
        // dedicated folder just computed above for a single file (see class docblock).
        $location = $isSingleFile ? $targetPath : $storageRoot;
        $this->client->setSavePath((string) ($torrent['hash'] ?? ''), $location);
        $download->incrementMoveAttempts();
        $this->entityManager->flush();
    }

    /**
     * basename() for a Windows-style ("\"-separated) path — content_path/the storage root are
     * always Windows paths regardless of the host OS running this test suite (Linux CI), so PHP's
     * own basename()/pathinfo() (DIRECTORY_SEPARATOR-aware) cannot be used here.
     */
    private static function basename(string $windowsPath): string
    {
        $normalized = str_replace('/', '\\', $windowsPath);
        $separatorPosition = strrpos($normalized, '\\');

        return $separatorPosition === false ? $normalized : substr($normalized, $separatorPosition + 1);
    }

    /**
     * Strips a trailing ".ext" off $filename — a leading dot (a dotfile-style name with no real
     * extension) is not stripped, since there would be nothing left of the name otherwise.
     */
    private static function withoutExtension(string $filename): string
    {
        $dotPosition = strrpos($filename, '.');

        return $dotPosition === false || $dotPosition === 0 ? $filename : substr($filename, 0, $dotPosition);
    }
}
