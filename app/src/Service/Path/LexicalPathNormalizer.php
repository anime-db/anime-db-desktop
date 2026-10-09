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

namespace App\Service\Path;

/**
 * Purely lexical (no disk access) path resolution shared by everything that has to compare
 * absolute Windows paths without touching the filesystem — {@see \App\Service\Download\DownloadFolderJail}
 * and {@see \App\Service\Storage\ManualLinkService}.
 */
final class LexicalPathNormalizer
{
    public const string LONG_PATH_PREFIX = '\\\\?\\';

    /**
     * Normalizes separators and resolves "." / ".." segments purely lexically (no disk access),
     * the same "resolve both sides, compare with a trailing-separator boundary prefix" approach
     * native/protocols/app-media.js already uses for the media-file jail. A leading "\\?\" is
     * stripped first so a long-path-prefixed and a plain path compare equal. The result is always
     * backslash-separated, without a trailing separator.
     */
    public static function normalize(string $path): string
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

    /**
     * Whether $normalizedPath is $normalizedRoot or lies beneath it. Both arguments must already
     * be the output of {@see self::normalize()}. Case-folded: Windows filesystems are
     * case-insensitive (NTFS is case-preserving, not case-sensitive), so a byte-identical
     * comparison would reject a legitimately-inside path over nothing but a differently-cased
     * drive letter or folder name.
     */
    public static function isWithin(string $normalizedRoot, string $normalizedPath): bool
    {
        $root = mb_strtolower($normalizedRoot);
        $path = mb_strtolower($normalizedPath);

        return $path === $root || str_starts_with($path, $root.'\\');
    }
}
