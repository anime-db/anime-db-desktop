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
 * Glues the three source interfaces and {@see I18nCoverageIssueDecider} to
 * {@see I18nCoverageIssueExecutor}: fetches the app/plugin key sets, the current issue snapshot
 * and the resolved owner(s), asks the pure decider what to do, and — unless `$dryRun` — carries
 * that decision out.
 *
 * `$dryRun` is checked in exactly one place, right before any executor call: every source is
 * still read (so the printed decision reflects the real current state), only the write side is
 * skipped.
 */
final class I18nCoverageSync
{
    /**
     * The plugins monorepo already carries this label on both existing plugin ids with an empty
     * description ({@see Github\GhIssueExecutor}); `<plugin-id>` labels
     * created here follow the same convention.
     */
    public const string PLUGIN_LABEL_DESCRIPTION = '';

    public function __construct(
        private readonly I18nCoverageIssueDecider $decider = new I18nCoverageIssueDecider(),
    ) {
    }

    public function run(
        string $pluginId,
        ApplicationTranslationKeysSource $appKeys,
        PluginTranslationKeysSource $pluginKeys,
        I18nCoverageIssueStateSource $issueState,
        CodeownersSource $codeowners,
        I18nCoverageIssueExecutor $executor,
        bool $dryRun,
    ): I18nCoverageDecision {
        $issue = $issueState->currentState();
        $owners = CodeownersOwnerResolver::resolve($codeowners->content(), $pluginId);

        $decision = $this->decider->decide($pluginId, $appKeys->keys(), $pluginKeys->keys(), $issue, $owners);

        if (!$dryRun) {
            $this->apply($pluginId, $decision, $issue, $issueState, $executor);
        }

        return $decision;
    }

    private function apply(
        string $pluginId,
        I18nCoverageDecision $decision,
        I18nCoverageIssueSnapshot $issue,
        I18nCoverageIssueStateSource $issueState,
        I18nCoverageIssueExecutor $executor,
    ): void {
        switch ($decision->action) {
            case I18nCoverageAction::NONE:
                break;

            case I18nCoverageAction::CLOSE:
                \assert($issue->number !== null);
                $executor->close($issue->number);
                break;

            case I18nCoverageAction::CREATE:
                if (!$issueState->hasLabel($pluginId)) {
                    $executor->createLabel($pluginId, self::PLUGIN_LABEL_DESCRIPTION);
                }
                \assert($decision->body !== null);
                $executor->createIssue(
                    \sprintf('Translation catalog is behind the app (%s)', $pluginId),
                    $decision->body,
                    ['i18n-coverage', $pluginId],
                );
                break;

            case I18nCoverageAction::REWRITE_BODY:
                \assert($issue->number !== null && $decision->body !== null);
                $executor->rewriteBody($issue->number, $decision->body);
                break;

            case I18nCoverageAction::REWRITE_BODY_AND_COMMENT:
                \assert($issue->number !== null && $decision->body !== null && $decision->comment !== null);
                $executor->rewriteBody($issue->number, $decision->body);
                $executor->addComment($issue->number, $decision->comment);
                break;
        }
    }
}
