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
 * The only production {@see LocaleDisplayNameProvider}: a thin wrapper around `ext-intl`'s
 * `\Locale::getDisplayName()`, chosen over the `symfony/intl` package because the runtime already
 * ships `ext-intl` (see the FrankenPHP build table in `.claude-docs/architecture.md`), so pulling
 * in a userland polyfill would duplicate data already available natively.
 *
 * `class_exists()` guards against the extension being missing (the runtime ships it, but a CI/dev
 * PHP is assembled separately and can omit it, issue #461).
 *
 * `\Locale::getDisplayName()` returns `string|false`, not just `string` — `false` on a hard
 * failure (e.g. a locale string past ICU's length limit) — and for a code it merely does not
 * recognize its behavior is ICU-version-dependent (sometimes an empty string, sometimes the code
 * echoed back). Both `false` and `''` are normalized to null here, same as "extension
 * unavailable", rather than being surfaced as a valid endonym; anything else — including an
 * unrecognized code ICU chose to echo back rather than reject — is returned as-is.
 *
 * The `''` case is checked via `in_array()` rather than a direct `=== ''` comparison: PHPStan's
 * bundled stub for this function types its success case as `non-empty-string`, so a literal
 * `$name === ''` reads as provably-dead code to it even though the empty-string case above is a
 * real, documented possibility this method must still guard against.
 */
final class IntlLocaleDisplayNameProvider implements LocaleDisplayNameProvider
{
    public function getDisplayName(string $locale): ?string
    {
        if (!class_exists(\Locale::class)) {
            return null;
        }

        $name = \Locale::getDisplayName($locale, $locale);

        return \in_array($name, [false, ''], true) ? null : $name;
    }
}
