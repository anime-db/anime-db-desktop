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
use App\Repository\StorageRepository;
use App\Service\Exception\DownloadPathOutsideJailException;

/**
 * Decides whether a torrent's data still sits in a storage's hidden incoming directory
 * (`<storageRoot>\.anime-db\incoming\…`) — the only place the "Downloads" page lets
 * qBittorrent's `torrents/delete` remove data (issue #899). Data that was already moved out of
 * incoming into the storage root is library content and must never be deleted from here.
 *
 * The decision is made from the live `content_path` (falling back to `save_path`, as
 * {@see DownloadCompletionPoller} does) of a `torrents/info` entry, lexically through
 * {@see DownloadFolderJail} — never by comparing strings by hand, since the path may carry a
 * `\\?\` prefix and arbitrary casing. No disk access.
 */
final class DownloadIncomingChecker
{
    public function __construct(
        private readonly DownloadFolderJail $jail,
        private readonly StorageRepository $storages,
    ) {
    }

    /**
     * For a row with a `downloads` card: in incoming AND no relocation underway — once
     * {@see DownloadIncomingRelocator} has sent `setLocation` (moveAttempts > 0) qBittorrent moves
     * the files asynchronously and `content_path` may still show the old incoming path.
     *
     * @param ?array<string, mixed> $torrent
     */
    public function canDeleteDataOf(Download $download, ?array $torrent): bool
    {
        return $download->getMoveAttempts() === 0
            && $this->isInIncoming($torrent, $download->getTargetStorage()?->getPath());
    }

    /**
     * @param ?array<string, mixed> $torrent raw `torrents/info` entry, null when not in the client
     * @param ?string               $root    the row's `target_storage` path; null never matches
     */
    public function isInIncoming(?array $torrent, ?string $root): bool
    {
        $path = $this->pathOf($torrent);
        if ($path === null || $this->isMoving($torrent) || $root === null || $root === '') {
            return false;
        }

        return $this->isUnderIncomingOf($root, $path);
    }

    /**
     * For a torrent with no `downloads` row (so no target storage): incoming of any known storage.
     *
     * @param ?array<string, mixed> $torrent
     * @param ?list<string>         $roots   storage paths already loaded by the caller, to avoid a query per row
     */
    public function isInIncomingOfAnyStorage(?array $torrent, ?array $roots = null): bool
    {
        $path = $this->pathOf($torrent);
        if ($path === null || $this->isMoving($torrent)) {
            return false;
        }

        foreach ($roots ?? $this->storageRoots() as $root) {
            if ($root !== '' && $this->isUnderIncomingOf($root, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function storageRoots(): array
    {
        return array_values(array_map(
            static fn ($storage): string => $storage->getPath(),
            $this->storages->findAllOrderedByName(),
        ));
    }

    /** @param ?array<string, mixed> $torrent */
    private function isMoving(?array $torrent): bool
    {
        return ($torrent['state'] ?? null) === 'moving';
    }

    /** @param ?array<string, mixed> $torrent */
    private function pathOf(?array $torrent): ?string
    {
        $path = $torrent['content_path'] ?? $torrent['save_path'] ?? null;

        return \is_string($path) && $path !== '' ? $path : null;
    }

    private function isUnderIncomingOf(string $root, string $path): bool
    {
        try {
            $resolved = $this->jail->assertWithinRoot($root, $path);
        } catch (DownloadPathOutsideJailException) {
            return false;
        }

        return $this->jail->isUnderIncoming($this->jail->relativePathUnderRoot($root, $resolved));
    }
}
