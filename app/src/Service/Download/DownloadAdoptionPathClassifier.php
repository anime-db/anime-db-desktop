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

use App\Service\Exception\DownloadPathOutsideJailException;

/**
 * Strict classifier of a torrent's live `content_path` for {@see DownloadOrphanAdopter}. It never
 * touches the disk or the database: nested storages are legal and {@see
 * DownloadFolderJail::firstSegment()} fires at any depth, so without strict rules the storage
 * (and the folder the poller would link) would be ambiguous.
 *
 * Two pure steps, run by the caller with the marker check in between: {@see self::selectStorage()}
 * picks the candidate (the deepest root containing the path), {@see self::parse()} accepts exactly
 * three relative shapes under its root:
 *  - `.anime-db\incoming\<infoHash>\<name>` — incoming branch;
 *  - `<name>`                               — root branch, a multi-file torrent;
 *  - `<name>\<file>`                        — root branch, a single-file torrent.
 */
final class DownloadAdoptionPathClassifier
{
    private const string HIDDEN_DIR = '.anime-db';
    private const string INCOMING_SUBDIR = 'incoming';

    public function __construct(private readonly DownloadFolderJail $jail)
    {
    }

    /**
     * @param list<array{id: int, root: string}> $storages
     *
     * @throws DownloadAdoptionRefusedException
     */
    public function classify(string $contentPath, string $infoHash, array $storages, bool $isSingleFile): DownloadAdoptionPlan
    {
        $candidate = $this->selectStorage($contentPath, $storages);

        return $this->parse($contentPath, $infoHash, $candidate, $isSingleFile);
    }

    /**
     * @param list<array{id: int, root: string}> $storages
     *
     * @return array{id: int, root: string} the storage with the longest root containing the path
     *
     * @throws DownloadAdoptionRefusedException when no storage root contains the path
     */
    public function selectStorage(string $contentPath, array $storages): array
    {
        $best = null;
        foreach ($storages as $storage) {
            try {
                $this->jail->assertWithinRoot($storage['root'], $contentPath);
            } catch (DownloadPathOutsideJailException) {
                continue;
            }

            if ($best === null || \strlen(rtrim($storage['root'], '\\/')) > \strlen(rtrim($best['root'], '\\/'))) {
                $best = $storage;
            }
        }

        return $best ?? throw new DownloadAdoptionRefusedException('download_adopt.error_no_storage');
    }

    /**
     * @param array{id: int, root: string} $storage a candidate returned by {@see self::selectStorage()}
     *
     * @throws DownloadAdoptionRefusedException
     */
    public function parse(string $contentPath, string $infoHash, array $storage, bool $isSingleFile): DownloadAdoptionPlan
    {
        $resolved = $this->jail->assertWithinRoot($storage['root'], $contentPath);
        $relative = $this->jail->relativePathUnderRoot($storage['root'], $resolved);

        if ($relative === '') {
            throw new DownloadAdoptionRefusedException('download_adopt.error_path_is_root');
        }

        if ($this->jail->isBareIncomingRootForHash($relative, $infoHash)) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_bare_incoming');
        }

        $segments = explode('\\', $relative);

        if ($segments[0] === self::HIDDEN_DIR && ($segments[1] ?? null) === self::INCOMING_SUBDIR && isset($segments[2])) {
            return $this->parseIncoming($segments, $infoHash, $storage['id'], $isSingleFile);
        }

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new DownloadAdoptionRefusedException('download_adopt.error_hidden_segment');
            }
        }

        if (preg_match('/^[0-9a-fA-F]{40}$/', $segments[0]) === 1) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_hash_folder');
        }

        if (\count($segments) !== ($isSingleFile ? 2 : 1)) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_unexpected_depth');
        }

        // `<name>\<file>` is the shape DownloadIncomingRelocator::tryMove() produces, so <name> is the file's
        // name without extension; any other folder is shared with files that are not this torrent's.
        if ($isSingleFile && $segments[0] !== self::withoutExtension($segments[1])) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_shared_folder', ['%path%' => $segments[0]]);
        }

        return new DownloadAdoptionPlan($storage['id'], DownloadAdoptionBranch::Root, $segments[0]);
    }

    /**
     * @param non-empty-list<string> $segments `.anime-db`, `incoming`, a hash segment and whatever follows
     */
    private function parseIncoming(array $segments, string $infoHash, int $storageId, bool $isSingleFile): DownloadAdoptionPlan
    {
        if ($segments[2] !== $infoHash) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_foreign_incoming');
        }

        foreach (\array_slice($segments, 3) as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new DownloadAdoptionRefusedException('download_adopt.error_hidden_segment');
            }
        }

        // Bare `.anime-db\incoming\<infoHash>` was refused by the caller already, so a name exists.
        if (\count($segments) !== 4) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_unexpected_depth');
        }

        // Same name DownloadIncomingRelocator::tryMove() moves it to: a single file gets a folder
        // named after the file without its extension.
        $name = $isSingleFile ? self::withoutExtension($segments[3]) : $segments[3];

        return new DownloadAdoptionPlan($storageId, DownloadAdoptionBranch::Incoming, $name);
    }

    private static function withoutExtension(string $filename): string
    {
        $dotPosition = strrpos($filename, '.');

        return $dotPosition === false || $dotPosition === 0 ? $filename : substr($filename, 0, $dotPosition);
    }
}
