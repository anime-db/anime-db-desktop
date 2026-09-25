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

use App\Service\I18nCoverage\AmbiguousIssueStateException;
use App\Service\I18nCoverage\CodeownersOwnerResolver;
use App\Service\I18nCoverage\CodeownersSource;
use App\Service\I18nCoverage\I18nCoverageIssueExecutor;
use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\I18nCoverage\I18nCoverageIssueStateSource;
use App\Service\PluginContracts\PluginContractPins;
use App\Service\PluginContracts\PluginContractsCheckException;
use App\Service\PluginContracts\PluginContractsLagDetector;

/**
 * Reconciles the tracking issue of one plugin. The caller has already validated the registry and
 * the contracts version ({@see PluginContractsLagDetector::detect()} with no plugins); everything
 * that can go wrong here is specific to this plugin and ends as {@see DriftAction::CANNOT_CHECK}
 * without any write.
 */
final class PluginContractsDriftSync
{
    public const string TRACKING_LABEL = 'plugin-contracts-drift';

    public function __construct(
        private readonly DriftIssueDecider $decider = new DriftIssueDecider(),
        private readonly PluginContractsLagDetector $detector = new PluginContractsLagDetector(),
    ) {
    }

    public function run(
        PluginContractPins $pins,
        string $contractsVersion,
        string $appVersion,
        I18nCoverageIssueStateSource $issueState,
        CodeownersSource $codeowners,
        I18nCoverageIssueExecutor $executor,
        bool $dryRun,
    ): DriftDecision {
        if ($pins->problem !== null) {
            return DriftDecision::cannotCheck($pins->problem);
        }

        try {
            $lagging = $this->detector->detect($contractsVersion, [$pins])[0] ?? null;
        } catch (PluginContractsCheckException $exception) {
            return DriftDecision::cannotCheck($exception->getMessage());
        }

        try {
            $issue = $issueState->currentState();
            $owners = $lagging !== null ? CodeownersOwnerResolver::resolve($codeowners->content(), $pins->id) : [];
        } catch (AmbiguousIssueStateException $exception) {
            return DriftDecision::cannotCheck($exception->getMessage());
        }

        $decision = $this->decider->decide($lagging, $contractsVersion, $appVersion, $issue, $owners);

        if (!$dryRun) {
            $this->apply($pins->id, $decision, $issue, $issueState, $executor);
        }

        return $decision;
    }

    private function apply(
        string $pluginId,
        DriftDecision $decision,
        I18nCoverageIssueSnapshot $issue,
        I18nCoverageIssueStateSource $issueState,
        I18nCoverageIssueExecutor $executor,
    ): void {
        switch ($decision->action) {
            case DriftAction::NONE:
            case DriftAction::CANNOT_CHECK:
                break;

            case DriftAction::CLOSE:
                \assert($issue->number !== null);
                $executor->close($issue->number);
                break;

            case DriftAction::CREATE:
                if (!$issueState->hasLabel($pluginId)) {
                    $executor->createLabel($pluginId, '');
                }
                \assert($decision->body !== null);
                $executor->createIssue(\sprintf(DriftIssueBody::TITLE_FORMAT, $pluginId), $decision->body, [self::TRACKING_LABEL, $pluginId]);
                break;

            case DriftAction::REWRITE_BODY:
                \assert($issue->number !== null && $decision->body !== null);
                $executor->rewriteBody($issue->number, $decision->body);
                break;

            case DriftAction::REWRITE_BODY_AND_COMMENT:
                \assert($issue->number !== null && $decision->body !== null && $decision->comment !== null);
                $executor->rewriteBody($issue->number, $decision->body);
                $executor->addComment($issue->number, $decision->comment);
                break;
        }
    }
}
