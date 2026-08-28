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
 * Mirrors `mapOsLocaleToAppLocale()` in `native/config.js` (issue #538), the same way
 * {@see LocaleDirection} mirrors the RTL table from `native/i18n/index.js` (issue #450): the set
 * of locales resolving to "ru" changes close to never, and pulling it from Node into PHP at
 * runtime is more expensive than duplicating a short, stable table.
 *
 * Used to pick the nearest built-in locale for a translation fallback chain — a user whose
 * request locale is e.g. "kk" is understood better by a "ru" catalog than by an "en" one, the
 * same reasoning `native/config.js` already applies when resolving the tray/splash locale.
 */
final class NearestBuiltInLocale
{
    private const RU_PREFERRED_PREFIXES = ['ru', 'be', 'kk', 'ky', 'tg', 'uz', 'hy', 'az'];

    public function resolve(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return 'en';
        }

        $prefix = strtolower((preg_split('/[-_]/', $locale, 2) ?: [$locale])[0]);

        return \in_array($prefix, self::RU_PREFERRED_PREFIXES, true) ? 'ru' : 'en';
    }

    /**
     * The translator fallback chain for $locale: the nearest built-in locale (see {@see resolve()})
     * followed by "en", without duplicating "en" when it already is the nearest one. Kept here
     * rather than reimplemented at each call site (`LocaleSubscriber` and `SettingsController`)
     * so both stay in lockstep.
     *
     * @return list<string>
     */
    public function fallbackChain(?string $locale): array
    {
        $nearest = $this->resolve($locale);

        return $nearest === 'en' ? ['en'] : [$nearest, 'en'];
    }
}
