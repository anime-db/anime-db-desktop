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
 */
final class I18nCoverageIssueDecider
{
    /**
     * @param list<string> $appKeys    the app's own reference key set
     * @param list<string> $pluginKeys the plugin's own key set
     * @param list<string> $owners     `@handle` mentions for the plugin body, empty when unresolved
     */
    public function decide(
        string $pluginId,
        array $appKeys,
        array $pluginKeys,
        I18nCoverageIssueSnapshot $issue,
        array $owners,
    ): I18nCoverageDecision {
        $missingKeys = array_values(array_unique(array_diff($appKeys, $pluginKeys)));
        sort($missingKeys);

        if ($missingKeys === []) {
            return $issue->exists ? I18nCoverageDecision::close() : I18nCoverageDecision::none();
        }

        $body = I18nCoverageIssueBody::render($pluginId, $missingKeys, $owners);

        if (!$issue->exists) {
            return I18nCoverageDecision::create($missingKeys, $body);
        }

        $previousMissingKeys = I18nCoverageIssueBody::parsePreviousDelta($issue->body);

        if ($previousMissingKeys === $missingKeys) {
            return I18nCoverageDecision::rewriteBody($missingKeys, $body);
        }

        $comment = I18nCoverageIssueBody::renderComment($previousMissingKeys, $missingKeys);

        return I18nCoverageDecision::rewriteBodyAndComment($missingKeys, $body, $comment);
    }
}
