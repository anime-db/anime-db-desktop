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

namespace App\Tests\Unit\Translation;

/**
 * Compares Symfony-style %name% placeholders between two translations of the same catalog key.
 * The project deliberately uses only this one placeholder syntax (no ICU/pluralization
 * catalogs), so a stray '{', '}' or '|' is treated as a syntax violation rather than a
 * different-but-valid style.
 */
final class PlaceholderParity
{
    private const FORBIDDEN_CHARACTERS = ['{', '}', '|'];

    /**
     * @return list<string> sorted, deduplicated %name% placeholder names found in $value
     */
    public static function extract(string $value): array
    {
        preg_match_all('/%([a-zA-Z0-9_]+)%/', $value, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @return list<string> forbidden characters present in $value, in the order checked
     */
    public static function findForbiddenCharacters(string $value): array
    {
        return array_values(array_filter(
            self::FORBIDDEN_CHARACTERS,
            static fn (string $char): bool => str_contains($value, $char),
        ));
    }

    /**
     * Compares the placeholder sets of two translations of the same key. The order of
     * placeholders inside the string is irrelevant — target-language grammar may require a
     * different order than the source, and that is not a translation defect.
     *
     * @return array{missing: list<string>, extra: list<string>}|null null when the sets match
     */
    public static function diff(string $referenceValue, string $otherValue): ?array
    {
        $reference = self::extract($referenceValue);
        $other = self::extract($otherValue);

        $missing = array_values(array_diff($reference, $other));
        $extra = array_values(array_diff($other, $reference));

        if ($missing === [] && $extra === []) {
            return null;
        }

        return ['missing' => $missing, 'extra' => $extra];
    }
}
