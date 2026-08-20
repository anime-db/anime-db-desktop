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

namespace App\Service;

/**
 * Writing direction is a property of the language, not of a translation plugin (issue #450): a
 * plugin declares locales, not code, so it has no channel to ship a "dir" value, and the set of
 * RTL languages changes close to never. Direction is therefore resolved from a static table keyed
 * on the BCP 47 primary language subtag, kept in core, instead of a field on the plugin manifest.
 * A locale not in the table — including one supplied by a plugin the table has never heard of —
 * is treated as "ltr".
 */
final class LocaleDirection
{
    private const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur'];

    public function resolve(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return 'ltr';
        }

        $language = strtolower((preg_split('/[-_]/', $locale, 2) ?: [$locale])[0]);

        return \in_array($language, self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}
