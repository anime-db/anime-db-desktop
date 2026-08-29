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

use AnimeDb\PluginContracts\Manifest\PluginType;

/**
 * A {@see TranslationCoverageService} result, shaped by the plugin's manifest type (issue #540):
 * a {@see PluginType::Translation} plugin shares the app's own `messages` domain, so its catalog
 * can be compared key-by-key against the app's reference catalog (`$coverage`). An
 * {@see PluginType::Integration}/{@see PluginType::Local} plugin's catalog lives in its own
 * domain instead — there is nothing to compare it against, so `$coverage` stays empty and
 * `$locales` is the only thing the report carries: which locales the plugin actually ships.
 */
final class PluginTranslationReport
{
    /**
     * @param array<string, LocaleTranslationCoverage> $coverage keyed by locale; always empty
     *                                                           unless $type is Translation
     * @param list<string>                             $locales  sorted locales the plugin ships,
     *                                                           populated for every plugin type
     */
    private function __construct(
        public readonly PluginType $type,
        public readonly array $coverage,
        public readonly array $locales,
    ) {
    }

    /**
     * @param array<string, LocaleTranslationCoverage> $coverage
     */
    public static function translation(array $coverage): self
    {
        return new self(PluginType::Translation, $coverage, array_keys($coverage));
    }

    /**
     * @param list<string> $locales
     */
    public static function featureLocales(PluginType $type, array $locales): self
    {
        return new self($type, [], $locales);
    }
}
