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

namespace App\Service\Plugin\Filler;

/**
 * Downloads a plugin-supplied remote image URL into a local file under %AppData%/media/{id}/,
 * the same directory native/protocols/app-media.js serves covers/gallery images from (issue #68).
 * Kept behind an interface so {@see PluginAnimeDataMerger}'s merge-vs-overwrite rule (issue #231)
 * can be unit-tested without a real network call.
 */
interface PluginMediaDownloaderInterface
{
    /**
     * @param (\Closure():bool)|null $stillWanted asked right before the file would be written, after the slow
     *                                            part of the download; `false` means the entry was deleted in
     *                                            the meantime, so nothing is written (and no directory
     *                                            created) and null is returned
     *
     * @return string|null the downloaded file's name relative to %AppData%/media/{$animeId}/, or
     *                     null when the download failed (network error, non-2xx response, empty
     *                     body, oversized body) — failure is not fatal to the caller, which
     *                     leaves the field it was about to overwrite untouched
     */
    public function download(int $animeId, string $url, ?\Closure $stillWanted = null): ?string;
}
