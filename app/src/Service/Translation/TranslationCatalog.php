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

namespace App\Service\Translation;

use Symfony\Component\Yaml\Yaml;

/**
 * The one place a `messages.<locale>.yaml` catalog file gets read and its nested keys flattened
 * into `parent.child` dot-notation, so a coverage check and a catalog-parity test walk the exact
 * same tree the exact same way instead of risking two flatteners drifting apart silently.
 *
 * {@see self::loadFile()} never throws: a missing file or YAML that fails to parse (or parses to
 * something other than a key => value/array tree) both come back as null, "coverage unknown" for
 * whatever reads it — a plugin's catalog file can be anything, since it arrived as an arbitrary
 * ZIP archive.
 */
final class TranslationCatalog
{
    /**
     * @return array<string, string>|null flattened dot-key => stringified value, or null when
     *                                    the file is missing or does not parse as a catalog
     */
    public static function loadFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($parsed)) {
            return null;
        }

        return self::flatten($parsed);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $values = [...$values, ...self::flatten($value, $fullKey)];
            } else {
                $values[$fullKey] = (string) $value;
            }
        }

        return $values;
    }
}
