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

/**
 * The locale switcher's option labels used to be translation keys (`settings.locale.ru`), but
 * {@see Plugin\AvailableLocalesProvider} (issue #453) made the locale set dynamic —
 * a translation plugin can add a locale core has never heard of, and core cannot pre-populate a
 * label for every locale a plugin might ever bring, nor can a plugin add a key to core's own
 * catalog (issue #461). An endonym — a language's name for itself, resolved from the locale code
 * alone — is the one label that needs no catalog entry on either side and scales to any locale.
 *
 * Resolved via {@see LocaleDisplayNameProvider} (backed by `ext-intl`'s `\Locale::getDisplayName()`
 * in production) rather than the `symfony/intl` package: the runtime already ships `ext-intl` (see
 * the FrankenPHP build table in `.claude-docs/architecture.md`), so pulling in a userland polyfill
 * would duplicate data already available natively.
 *
 * Falls back to the raw locale code (e.g. `de`) whenever the provider returns null — whether
 * because `ext-intl` is unavailable or because ICU does not recognize the code — logged at
 * `warning` level rather than silently: `ext-intl` missing at runtime means the FrankenPHP build
 * lost an extension it is supposed to ship, which is worth surfacing, not swallowing.
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
            return $endonym;
        }

        $this->logger->warning('locale endonym: no endonym available for locale "{locale}" (ext-intl missing or the code is unrecognized), falling back to the raw code.', [
            'locale' => $locale,
        ]);

        return $locale;
    }
}
