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
 * Resolved via {@see LocaleDisplayNameProvider} — this class only knows the interface, not what
 * backs it, so it stays correct regardless of which implementation is wired in.
 *
 * A resolved endonym has its first letter capitalized: ICU spells some endonyms lower-case
 * (`русский`, `français`) by the target language's own orthography, but a language switcher lists
 * several languages side by side, and macOS, Windows and Wikipedia all capitalize uniformly there
 * rather than mixing case across entries. `u()->title()` only touches the first character, so
 * multi-word endonyms (`беларуская мова`) and already-capitalized ones (`Deutsch`, `日本語`) are
 * unaffected.
 *
 * Falls back to the raw locale code (e.g. `de`) whenever the provider returns null, logged at
 * `warning` level rather than silently: the provider being unable to resolve a locale it is
 * expected to handle is worth surfacing, not swallowing. The fallback code is returned as-is,
 * uncapitalized — capitalization is a presentation choice for a resolved endonym, not for a raw
 * locale code.
 */
final class LocaleEndonymResolver
{
    public function __construct(
        private readonly LocaleDisplayNameProvider $displayNameProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $locale): string
    {
        $endonym = $this->displayNameProvider->getDisplayName($locale);
        if ($endonym !== null) {
            return u($endonym)->title()->toString();
        }

        $this->logger->warning('locale endonym: display name provider returned no result for locale "{locale}", falling back to the raw code.', [
            'locale' => $locale,
        ]);

        return $locale;
    }
}
