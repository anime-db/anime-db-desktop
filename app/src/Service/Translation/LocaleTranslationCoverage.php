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

/**
 * How well a single locale of a translation plugin's catalog covers the app's reference key set
 * for that same domain, produced by {@see TranslationCoverageService}.
 *
 * `isKnown` is false when the plugin's catalog file for this locale was missing or failed to
 * parse — every other field is meaningless in that case (left at empty/zero) rather than treated
 * as "zero coverage", which would misrepresent a catalog that simply could not be read.
 */
final class LocaleTranslationCoverage
{
    /**
     * @param list<string>                                                     $missing               reference keys absent from the plugin catalog
     * @param list<string>                                                     $orphans               plugin catalog keys absent from the reference
     * @param array<string, array{missing: list<string>, extra: list<string>}> $placeholderMismatches keyed by the shared catalog key
     */
    private function __construct(
        public readonly string $locale,
        public readonly bool $isKnown,
        public readonly int $covered,
        public readonly array $missing,
        public readonly array $orphans,
        public readonly array $placeholderMismatches,
    ) {
    }

    public static function unknown(string $locale): self
    {
        return new self($locale, false, 0, [], [], []);
    }

    /**
     * @param array<string, string> $reference flattened dot-key => value of the app's own reference catalog
     * @param array<string, string> $plugin    flattened dot-key => value of the plugin's catalog for this locale
     */
    public static function compute(string $locale, array $reference, array $plugin): self
    {
        $referenceKeys = array_keys($reference);
        $pluginKeys = array_keys($plugin);

        $covered = array_intersect($referenceKeys, $pluginKeys);
        $missing = array_values(array_diff($referenceKeys, $pluginKeys));
        $orphans = array_values(array_diff($pluginKeys, $referenceKeys));
        sort($missing);
        sort($orphans);

        $placeholderMismatches = [];
        foreach ($covered as $key) {
            $diff = PlaceholderParity::diff($reference[$key], $plugin[$key]);
            if ($diff !== null) {
                $placeholderMismatches[$key] = $diff;
            }
        }

        return new self($locale, true, \count($covered), $missing, $orphans, $placeholderMismatches);
    }
}
