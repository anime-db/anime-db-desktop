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

namespace App\Service\PluginContracts\Drift;

use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\PluginContracts\LaggingPlugin;

final class DriftIssueDecider
{
    /**
     * @param LaggingPlugin|null $lagging null when the plugin does not lag
     * @param list<string>       $owners  `@handle` mentions for the body, empty when unresolved
     */
    public function decide(
        ?LaggingPlugin $lagging,
        string $contractsVersion,
        string $appVersion,
        I18nCoverageIssueSnapshot $issue,
        array $owners,
    ): DriftDecision {
        if ($lagging === null) {
            return $issue->exists ? DriftDecision::close() : DriftDecision::none();
        }

        $body = DriftIssueBody::render($lagging, $contractsVersion, $appVersion, $owners);

        if (!$issue->exists) {
            return DriftDecision::create($body);
        }

        $current = new DriftMarker($contractsVersion, $lagging->reason);
        $previous = DriftMarker::parse($issue->body);

        if ($previous !== null && $previous->equals($current)) {
            return DriftDecision::rewriteBody($body);
        }

        return DriftDecision::rewriteBodyAndComment($body, DriftIssueBody::renderComment($previous, $current));
    }
}
