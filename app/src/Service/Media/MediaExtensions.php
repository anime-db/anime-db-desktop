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

namespace App\Service\Media;

/** Lower-case file extensions (without the dot) grouped by media kind. */
final class MediaExtensions
{
    /**
     * Video containers. Also the whitelist ScanStorageService filters top-level files by, and
     * the set FilenameCleaner strips from a name.
     *
     * @var string[]
     */
    public const VIDEO = [
        'avi', 'mkv', 'm1v', 'm2v', 'm4v', 'mov', 'qt', 'mpeg', 'mpg', 'mpe',
        'ogg', 'rm', 'wmv', 'asf', 'wm', 'm2ts', 'mts', 'm2t', 'mp4', '3gp',
        '3g2', 'k3g', 'mp2', 'mpv2', 'mod', 'vob', 'f4v', 'ismv', 'webm', 'ts',
    ];

    /** @var string[] */
    public const AUDIO = [
        'mp3', 'flac', 'aac', 'm4a', 'wav', 'wma', 'opus', 'oga', 'ac3', 'dts',
        'mka', 'ape', 'wv', 'aiff', 'aif', 'ra',
    ];

    /** @var string[] */
    public const SUBTITLES = [
        'srt', 'ass', 'ssa', 'sub', 'idx', 'sup', 'vtt', 'smi', 'sbv',
    ];

    /** Whether $extension (any case) belongs to the video, audio or subtitle set. */
    public static function isMedia(string $extension): bool
    {
        $extension = strtolower($extension);

        return \in_array($extension, self::VIDEO, true)
            || \in_array($extension, self::AUDIO, true)
            || \in_array($extension, self::SUBTITLES, true);
    }
}
