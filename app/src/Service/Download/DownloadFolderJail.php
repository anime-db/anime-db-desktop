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

use App\Service\AppSettingsProvider;
use App\Service\Exception\DownloadPathOutsideJailException;

/**
 * Keeps every download-related filesystem path inside the user-configured downloads root
 * (AppSettingsProvider::getDownloadsRoot(), default "%USERPROFILE%\Downloads", issue #346).
 *
 * A `.torrent` file is untrusted input — its internal file list can carry "../", absolute or
 * UNC entries — so the save-path handed to qBittorrent is always ONE THAT THIS CLASS BUILT
 * (resolveSavePathForInfoHash()), never a path derived from the torrent's own names. Validating
 * a torrent's internal per-file paths is libtorrent's job (it already refuses to escape
 * save-path when writing files); this jail's job stops at "the save-path we hand qBittorrent,
 * and the completion path qBittorrent hands back, both stay under the configured root."
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

    public function __construct(private readonly AppSettingsProvider $settings)
    {
    }

    public function getRoot(): string
    {
        return rtrim($this->settings->getDownloadsRoot(), '\\/');
    }

    /**
     * Builds the save-path a newly enqueued infoHash is downloaded into: one dedicated
     * subdirectory per torrent under the root, long-path-prefixed so a deeply nested release
     * name cannot blow past Windows' 260-char MAX_PATH mid-download.
     */
    public function resolveSavePathForInfoHash(string $infoHash): string
    {
        return $this->toLongPathAware($this->getRoot().'\\'.$infoHash);
    }

    /**
     * Resolves $path lexically and asserts it falls inside the configured root, returning the
     * normalized (non-long-path-prefixed) form for the caller to use. Throws otherwise — used
     * both defensively on the save-path this class itself builds and, more importantly, on the
     * content_path qBittorrent reports back for a completed torrent before AnimeDownloadLinker
     * is allowed to read anything under it.
     */
    public function assertWithinRoot(string $path): string
    {
        $root = $this->normalize($this->getRoot());
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
