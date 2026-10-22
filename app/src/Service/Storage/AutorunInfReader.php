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
 * Reader of the `autorun.inf` file contents: decodes the bytes and looks a key up in a section,
 * ignoring case. It works on a string and never touches the filesystem.
 *
 * The file may be written by a person or by an application in different encodings, so the order is:
 * UTF-16 LE BOM, UTF-8 BOM, valid UTF-8, else cp1251.
 */
final class AutorunInfReader
{
    public function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
    }

    /** The trimmed value of the first matching key in the section, null when absent or empty. */
    public function getValue(string $bytes, string $section, string $key): ?string
    {
        $section = strtolower($section);
        $key = strtolower($key);
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $this->decode($bytes)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';') {
                continue;
            }

            if ($line[0] === '[' && str_ends_with($line, ']')) {
                $current = strtolower(trim(substr($line, 1, -1)));
                continue;
            }

            if ($current !== $section || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            if (strtolower(trim($name)) === $key) {
                $value = trim($value);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
