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

namespace App\Service\I18nCoverage;

use App\Service\Translation\PlaceholderParity;

/**
 * Decides what, if anything, should happen to a plugin's `i18n-coverage` issue on this run —
 * issue #515's five-row state table. A pure function of already-resolved values (two key sets,
 * the current issue snapshot, resolved owners): no I/O, no `Github\*` collaborator in sight, so a
 * literal array fixture is enough to exercise every row without a fake in sight either (mirrors
 * {@see \AnimeDb\Plugins\Tools\VersionBumpChecker} in `anime-db-plugins`, which keeps the same
 * gate logic testable without a real git checkout).
 *
 * The delta is always recomputed from scratch — nothing here accumulates across runs. The only
 * thing carried from the previous run is the previous delta, and that is read back out of the
 * issue's own body ({@see I18nCoverageIssueBody::parsePreviousDelta()}), not out of any state
 * this class keeps.
 *
 * Orphaned keys (`pluginKeys \ appKeys` — the reverse subtraction of the same two sets `missing`
 * comes from) are rendered into the body as a purely informational section
 * ({@see I18nCoverageIssueBody::render()}) but never feed into the five-row decision table below:
 * every action here is still a function of `missing` and the placeholder-divergent keys alone. In particular a plugin with orphaned
 * keys but no missing ones still gets its issue closed — orphaned keys have no bearing on whether
 * an issue should exist at all.
 */
final class I18nCoverageIssueDecider
{
    /**
     * @param list<string>                         $appKeys              the app's own reference key set
     * @param list<string>                         $pluginKeys           the plugin's own key set
     * @param list<string>                         $owners               `@handle` mentions for the plugin body, empty when unresolved
     * @param array<string, string>                $appValues            the app's reference key => value map
     * @param array<string, array<string, string>> $pluginValuesByLocale locale => (key => value) of the plugin
     */
    public function decide(
        string $pluginId,
        array $appKeys,
        array $pluginKeys,
        I18nCoverageIssueSnapshot $issue,
        array $owners,
        array $appValues = [],
        array $pluginValuesByLocale = [],
    ): I18nCoverageDecision {
        $missingKeys = array_values(array_unique(array_diff($appKeys, $pluginKeys)));
        sort($missingKeys);

        $divergent = self::divergentPlaceholders($appKeys, $pluginKeys, $appValues, $pluginValuesByLocale);
        $divergentKeys = array_keys($divergent);

        if ($missingKeys === [] && $divergentKeys === []) {
            return $issue->exists ? I18nCoverageDecision::close() : I18nCoverageDecision::none();
        }

        $orphanedKeys = array_values(array_unique(array_diff($pluginKeys, $appKeys)));
        sort($orphanedKeys);

        $body = I18nCoverageIssueBody::render($pluginId, $missingKeys, $owners, $orphanedKeys, $divergent);

        if (!$issue->exists) {
            return I18nCoverageDecision::create($missingKeys, $body, $divergentKeys);
        }

        $previousMissingKeys = I18nCoverageIssueBody::parsePreviousDelta($issue->body);
        $previousDivergentKeys = I18nCoverageIssueBody::parsePreviousDivergentDelta($issue->body);

        if ($previousMissingKeys === $missingKeys && $previousDivergentKeys === $divergentKeys) {
            return I18nCoverageDecision::rewriteBody($missingKeys, $body, $divergentKeys);
        }

        $comment = I18nCoverageIssueBody::renderComment(
            $previousMissingKeys,
            $missingKeys,
            $previousDivergentKeys,
            $divergentKeys,
        );

        return I18nCoverageDecision::rewriteBodyAndComment($missingKeys, $body, $comment, $divergentKeys);
    }

    /**
     * Keys present both in the app and in the plugin whose %name% placeholder set differs from the
     * app's in at least one plugin locale. Orphaned keys have no reference value and are skipped.
     *
     * @param list<string>                         $appKeys
     * @param list<string>                         $pluginKeys
     * @param array<string, string>                $appValues
     * @param array<string, array<string, string>> $pluginValuesByLocale
     *
     * @return array<string, array<string, array{missing: list<string>, extra: list<string>}>> key => locale => diff, both levels sorted
     */
    private static function divergentPlaceholders(
        array $appKeys,
        array $pluginKeys,
        array $appValues,
        array $pluginValuesByLocale,
    ): array {
        $shared = array_values(array_unique(array_intersect($appKeys, $pluginKeys)));
        sort($shared);
        ksort($pluginValuesByLocale);

        $result = [];
        foreach ($shared as $key) {
            if (!isset($appValues[$key])) {
                continue;
            }

            foreach ($pluginValuesByLocale as $locale => $catalog) {
                if (!isset($catalog[$key])) {
                    continue;
                }

                $diff = PlaceholderParity::diff($appValues[$key], $catalog[$key]);
                if ($diff !== null) {
                    $result[$key][(string) $locale] = $diff;
                }
            }
        }

        return $result;
    }
}
