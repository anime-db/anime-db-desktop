<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Entity;

/**
 * Single place for the case/whitespace-insensitive comparison key used on both sides of
 * name matching: written into Anime::$normalizedTitle and AnimeName::$normalizedName at
 * persist time, and applied again to a lookup value (e.g. a cleaned scan filename in
 * App\Service\Storage\OrphanAnimeMatcher) before comparing against those columns. Sharing
 * one function keeps the write side and the read side from drifting apart.
 *
 * Uses mb_strtolower() (full Unicode case folding) rather than SQL LOWER(), which under
 * SQLite without the ICU extension only folds ASCII letters.
 */
final class NameNormalizer
{
    public static function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }

    private function __construct()
    {
    }
}
