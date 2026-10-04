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
 * Keeps every download-related filesystem path inside the root of the Storage a download was
 * enqueued into (issue #851) — every caller passes that Storage's path in explicitly; this class
 * has no notion of a single global root of its own (that was the pre-#851 design, removed along
 * with AppSettingsProvider::getDownloadsRoot()/setDownloadsRoot()).
 *
 * A `.torrent` file is untrusted input — its internal file list can carry "../", absolute or
 * UNC entries — so the save-path handed to qBittorrent is always ONE THAT THIS CLASS BUILT
 * (resolveIncomingSavePathForInfoHash()), never a path derived from the torrent's own names.
 * Validating a torrent's internal per-file paths is libtorrent's job (it already refuses to
 * escape save-path when writing files); this jail's job stops at "the save-path we hand
 * qBittorrent, and the completion path qBittorrent hands back, both stay under the storage root."
 *
 * A newly enqueued torrent is saved under a hidden `<storageRoot>\.anime-db\incoming\<infoHash>`
 * subdirectory, not directly under the storage root: a finished download must land as exactly one
 * new top-level entry for the storage scanner to recognize it (issue #852 moves it there out of
 * incoming), and an in-progress download sitting directly in the storage root would both confuse
 * the scanner and risk a filename collision with something already in the library.
 *
 * Resolution here is purely lexical (no realpath()/disk access): a save-path is built and handed
 * to qBittorrent before the directory exists, and a reported content_path may reference a
 * filesystem this process cannot see in tests — resolving symlinks or querying existence would
 * make this class untestable and wouldn't add any safety realpath() itself doesn't already
 * bypass for a determined attacker anyway (junction points, not symlinks, are the real Windows
 * risk here, and out of scope for a lexical jail either way).
 */
final class DownloadFolderJail
{
    private const string WINDOWS_ABSOLUTE_PATH_PATTERN = '/^[A-Za-z]:\\\\/';
    private const string LONG_PATH_PREFIX = '\\\\?\\';
    private const string INCOMING_DIR_NAME = '.anime-db';
    private const string INCOMING_SUBDIR_NAME = 'incoming';

    /**
     * The hidden directory a storage's incoming (not-yet-linked) downloads live under:
     * `<storageRoot>\.anime-db`. The caller (QbittorrentDownloadService) is responsible for
     * creating this directory and hiding it on disk before a save-path under it is ever handed
     * to qBittorrent — this method only computes the path.
     */
    public function incomingRoot(string $storageRoot): string
    {
        return rtrim($storageRoot, '\\/').'\\'.self::INCOMING_DIR_NAME;
    }

    /**
     * Builds the save-path a newly enqueued infoHash is downloaded into: one dedicated
     * subdirectory per torrent under the storage's hidden incoming directory, long-path-prefixed
     * so a deeply nested release name cannot blow past Windows' 260-char MAX_PATH mid-download.
     */
    public function resolveIncomingSavePathForInfoHash(string $storageRoot, string $infoHash): string
    {
        return $this->toLongPathAware($this->incomingRoot($storageRoot).'\\'.self::INCOMING_SUBDIR_NAME.'\\'.$infoHash);
    }

    /**
     * Computes $resolvedPath's location relative to $root by normalizing $root through the same
     * lexical resolution {@see self::assertWithinRoot()} already applied to $resolvedPath (issue
     * #852) — cutting by the raw, unnormalized $root's length instead (the pre-#852 bug) misaligns
     * the cut whenever normalize() changes $root's length, e.g. collapsing a UNC root's leading
     * "\\\\" during resolution, which silently truncated the first characters of the resulting
     * relative path.
     */
    public function relativePathUnderRoot(string $root, string $resolvedPath): string
    {
        $normalizedRoot = rtrim($this->normalize(rtrim($root, '\\/')), '\\/');

        return ltrim(substr($resolvedPath, \strlen($normalizedRoot)), '\\/');
    }

    /**
     * Whether $relativePath (as returned by {@see self::relativePathUnderRoot()}) falls under a
     * storage's hidden incoming directory — a completed torrent still sitting there has not been
     * moved into the storage root yet (issue #852).
     */
    public function isUnderIncoming(string $relativePath): bool
    {
        return str_starts_with($relativePath, self::INCOMING_DIR_NAME.'\\'.self::INCOMING_SUBDIR_NAME.'\\');
    }

    /**
     * Whether $relativePath IS a torrent's own hidden incoming directory
     * (`.anime-db\incoming\<infoHash>`) with nothing beneath it, rather than a named entry under
     * it. Reachable when qBittorrent's global "don't create a subfolder" option is on for a
     * multi-file torrent: its files then land directly inside that directory instead of under a
     * name-carrying subfolder, so there is nothing for {@see
     * \App\Service\Download\DownloadIncomingRelocator::tryMove()} to safely derive a move target's
     * name from (basename() of this path is $infoHash, not a real name).
     */
    public function isBareIncomingRootForHash(string $relativePath, string $infoHash): bool
    {
        return $relativePath === self::INCOMING_DIR_NAME.'\\'.self::INCOMING_SUBDIR_NAME.'\\'.$infoHash;
    }

    /**
     * The first path component of $relativePath — the name a completed download must be linked
     * under (or, if it starts with ".", the hidden top-level entry a download must never be
     * linked from) once $relativePath is no longer under incoming.
     */
    public function firstSegment(string $relativePath): string
    {
        $separatorPosition = strpos($relativePath, '\\');

        return $separatorPosition === false ? $relativePath : substr($relativePath, 0, $separatorPosition);
    }

    /**
     * Resolves $path lexically and asserts it falls inside $root, returning the normalized
     * (non-long-path-prefixed) form for the caller to use. Throws otherwise — used both
     * defensively on the save-path this class itself builds and, more importantly, on the
     * content_path qBittorrent reports back for a completed torrent before AnimeDownloadLinker
     * is allowed to read anything under it.
     */
    public function assertWithinRoot(string $root, string $path): string
    {
        $root = $this->normalize(rtrim($root, '\\/'));
        $resolved = $this->normalize($path);
        // normalize() always canonicalizes to backslash-separated form (see its docblock) —
        // the boundary separator below must match that, not the host OS's DIRECTORY_SEPARATOR
        // (this test suite runs on Linux CI, but downloads roots/save-paths are Windows paths).
        $boundary = $root.'\\';

        // Windows filesystems are case-insensitive (NTFS is case-preserving, not case-sensitive):
        // qBittorrent/libtorrent is free to echo content_path back with different segment casing
        // than the configured downloads root, so the boundary check has to fold case — a
        // byte-identical comparison here would reject a legitimately-inside path over nothing
        // but a differently-cased drive letter or folder name. The returned $resolved keeps its
        // original casing; only this membership check is case-folded.
        if (mb_strtolower($resolved) !== mb_strtolower($root) && !str_starts_with(mb_strtolower($resolved), mb_strtolower($boundary))) {
            throw new DownloadPathOutsideJailException(\sprintf('"%s" resolves outside the downloads root "%s".', $path, $root));
        }

        return $resolved;
    }

    /**
     * Prefixes an absolute Windows path with "\\?\" so Windows API calls made against it (by
     * qbittorrent-nox/libtorrent) bypass the legacy 260-char MAX_PATH limit — the "\\?\-prefix"
     * mitigation from the issue's acceptance criteria. A no-op for anything else (already
     * prefixed, or a POSIX-style path, e.g. under the Linux CI test suite).
     */
    public function toLongPathAware(string $path): string
    {
        if (str_starts_with($path, self::LONG_PATH_PREFIX)) {
            return $path;
        }

        if (preg_match(self::WINDOWS_ABSOLUTE_PATH_PATTERN, $path) === 1) {
            return self::LONG_PATH_PREFIX.$path;
        }

        return $path;
    }

    /**
     * Normalizes separators and resolves "." / ".." segments purely lexically (no disk access),
     * the same "resolve both sides, compare with a trailing-separator boundary prefix" approach
     * native/protocols/app-media.js already uses for the media-file jail. A leading "\\?\" is
     * stripped first so a long-path-prefixed and a plain path compare equal.
     */
    private function normalize(string $path): string
    {
        $path = str_replace(self::LONG_PATH_PREFIX, '', $path);
        $path = str_replace('/', '\\', $path);

        $segments = explode('\\', $path);
        $stack = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Never pop the drive/UNC root itself (index 0) — mirrors how a real
                // filesystem clamps ".." at the root instead of erroring or escaping it.
                if (\count($stack) > 1) {
                    array_pop($stack);
                }

                continue;
            }

            $stack[] = $segment;
        }

        return implode('\\', $stack);
    }
}
