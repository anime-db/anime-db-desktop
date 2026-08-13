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

namespace App\Service\Storage;

/**
 * Turns a raw storage file/folder name into a best-guess anime title by
 * stripping rip/quality release tags, bracketed technical info and
 * separators. Pure text normalization, no catalog lookup or matching.
 */
final class FilenameCleaner
{
    /**
     * Video file extensions to strip before cleaning. A folder name never
     * matches one of these, so it is left as is. Also the whitelist ScanStorageService
     * filters top-level files by, so a file with an unrecognized extension is never
     * treated as a scannable item in the first place.
     *
     * @var string[]
     */
    public const EXTENSIONS = [
        'avi', 'mkv', 'm1v', 'm2v', 'm4v', 'mov', 'qt', 'mpeg', 'mpg', 'mpe',
        'ogg', 'rm', 'wmv', 'asf', 'wm', 'm2ts', 'mts', 'm2t', 'mp4', '3gp',
        '3g2', 'k3g', 'mp2', 'mpv2', 'mod', 'vob', 'f4v', 'ismv', 'webm', 'ts',
    ];

    /**
     * Quality/rip release tags to strip. Extensible via the constructor —
     * not meant to be an exhaustive hardcoded list.
     *
     * @var string[]
     */
    private const QUALITY_TAGS = [
        // ripping / source
        'CamRip', 'Cam', 'Telesync', 'TS', 'Telecine', 'TC',
        'Super Telesync', 'SuperTS', 'Super-TS',
        'VHS-Rip', 'VHSRip', 'Screener', 'Scr', 'VHS-Screener', 'VHSScr',
        'PPVRip', 'DVD-Screener', 'DVDScr', 'TV-Rip', 'TVRip',
        'Sat-Rip', 'SatRip', 'HDTV-Rip', 'HDTVRip', 'PDTVRip', 'HDRip',
        'BDRip', 'BDRemux', 'BD-Remux', 'BluRay', 'Blu-Ray', 'BD',
        'DVD-Rip', 'DVDRip', 'LaserDisc-RIP', 'LDRip', 'Workprint', 'WP',
        'WebRip', 'Web-DL', 'WebDL', 'Web-DLRip', 'WEB', 'Remux', 'DCPrip',
        // media
        'DVD', 'DVD5', 'DVD9', 'DVD10', 'DVD18',
        // subtitles / audio track
        'Sub', 'Subtitles', 'Dual Audio', 'DualAudio', 'Multi Sub', 'MultiSub',
        'Hardsub', 'Softsub', 'RAW', 'Subbed', 'Dubbed',
        // resolution
        '8K', '5K', '4K', '2160p', '1440p', '1080p', '720p', '576p', '480p',
        // video codec
        'x264', 'x265', 'H.264', 'H.265', 'HEVC', 'AVC', 'XviD', 'DivX', 'AV1',
        // color depth
        '10bit', '8bit', 'Hi10P', 'Hi444PP',
        // audio codec
        'AAC', 'AC3', 'DTS', 'DTS-HD', 'FLAC', 'TrueHD',
        // release notes
        'REPACK', 'PROPER', 'LIMITED', 'EXTENDED', 'UNCUT', 'REMASTERED',
    ];

    /** @var string[] */
    private readonly array $qualityTagPatterns;

    /**
     * @param string[] $additionalQualityTags Extra release tags merged with the built-in list
     */
    public function __construct(array $additionalQualityTags = [])
    {
        $this->qualityTagPatterns = array_map(
            static fn (string $tag): string => preg_quote($tag, '/'),
            [...self::QUALITY_TAGS, ...$additionalQualityTags],
        );
    }

    public function clean(string $name): string
    {
        $name = $this->stripExtension($name);
        $name = str_replace(['_', '.'], ' ', $name);

        $name = (string) preg_replace('/\[[^\[\]]*\]/u', ' ', $name);
        $name = (string) preg_replace('/\([^()]*\)/u', ' ', $name);
        $name = (string) preg_replace('/\{[^{}]*\}/u', ' ', $name);

        $name = $this->removeQualityTags($name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return trim($name, " \t\n\r\0\x0B.,-");
    }

    private function stripExtension(string $name): string
    {
        $lastDot = strrpos($name, '.');

        if ($lastDot === false) {
            return $name;
        }

        $extension = strtolower(substr($name, $lastDot + 1));

        if (!in_array($extension, self::EXTENSIONS, true)) {
            return $name;
        }

        return substr($name, 0, $lastDot);
    }

    private function removeQualityTags(string $name): string
    {
        $pattern = sprintf(
            '/(^|[\s.,-])(?:%s)(?=$|[\s.,-])/iu',
            implode('|', $this->qualityTagPatterns),
        );

        return (string) preg_replace($pattern, '$1', $name);
    }
}
