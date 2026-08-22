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

use Psr\Log\LoggerInterface;

use function Symfony\Component\String\u;

/**
 * The locale switcher's option labels used to be translation keys (`settings.locale.ru`), but
 * {@see Plugin\AvailableLocalesProvider} (issue #453) made the locale set dynamic —
 * a translation plugin can add a locale core has never heard of, and core cannot pre-populate a
 * label for every locale a plugin might ever bring, nor can a plugin add a key to core's own
 * catalog (issue #461). An endonym — a language's name for itself, resolved from the locale code
 * alone — is the one label that needs no catalog entry on either side and scales to any locale.
 *
 * Resolved via `ext-intl`'s `\Locale::getDisplayName()` — `ext-intl` is a required PHP extension
 * (see `composer.json`), so there is no "extension unavailable" path to abstract behind an
 * interface here.
 *
 * `\Locale::getDisplayName()` returns `string|false`, not just `string` — `false` on a hard
 * failure (e.g. a locale string past ICU's length limit) — and for a code it merely does not
 * recognize its behavior is ICU-version-dependent (sometimes an empty string, sometimes the code
 * echoed back). Both `false` and `''` are treated as "no result" here rather than being surfaced
 * as a valid endonym; anything else — including an unrecognized code ICU chose to echo back
 * rather than reject — is used as-is.
 *
 * The `''` case is checked via `in_array()` rather than a direct `=== ''` comparison: PHPStan's
 * bundled stub for this function types its success case as `non-empty-string`, so a literal
 * `$name === ''` reads as provably-dead code to it even though the empty-string case above is a
 * real, documented possibility this method must still guard against.
 *
 * A resolved endonym has its first letter capitalized: ICU spells some endonyms lower-case
 * (`русский`, `français`) by the target language's own orthography, but a language switcher lists
 * several languages side by side, and macOS, Windows and Wikipedia all capitalize uniformly there
 * rather than mixing case across entries. `u()->title()` only touches the first character, so
 * multi-word endonyms (`беларуская мова`) and already-capitalized ones (`Deutsch`, `日本語`) are
 * unaffected.
 *
 * Falls back to the raw locale code (e.g. `de`) whenever ICU produced no result, logged at
 * `warning` level rather than silently: ICU being unable to resolve a locale it is expected to
 * handle is worth surfacing, not swallowing. The fallback code is returned as-is, uncapitalized —
 * capitalization is a presentation choice for a resolved endonym, not for a raw locale code.
 */
final class LocaleEndonymResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $locale): string
    {
        $endonym = \Locale::getDisplayName($locale, $locale);
        if (!\in_array($endonym, [false, ''], true)) {
            return u($endonym)->title()->toString();
        }

        $this->logger->warning('locale endonym: ICU returned no result for locale "{locale}", falling back to the raw code.', [
            'locale' => $locale,
        ]);

        return $locale;
    }
}
