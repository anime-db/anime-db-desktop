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

namespace App\Entity;

/**
 * Reduces an incoming AnimeName::$locale value to the comparison key the host uses for
 * matching and deduplication (issue #724): lowercased, regional subtag stripped (`ru-RU`
 * becomes `ru`), and anything that does not look like a primary language subtag (2-3 ASCII
 * letters) collapses to null. Applied by AnimeName::__construct() to every locale, whatever
 * its source (plugin fill, seeder, import), so `$locale` is always already in canonical form
 * for a caller comparing it against another AnimeName's locale.
 *
 * Deliberately shape-only: it does not check the result against a real language registry, the
 * same way NameNormalizer does not check $name against a dictionary — a source can still send a
 * well-formed but made-up subtag, and that is out of scope here.
 */
final class LocaleNormalizer
{
    private const PATTERN = '/^[a-z]{2,3}$/';

    public static function normalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $value = str_replace('_', '-', mb_strtolower(trim($locale)));
        $primarySubtag = explode('-', $value, 2)[0];

        return preg_match(self::PATTERN, $primarySubtag) === 1 ? $primarySubtag : null;
    }

    private function __construct()
    {
    }
}
