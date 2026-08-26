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
 * Write side of a plugin's `i18n-coverage` tracking issue and its `<plugin-id>` label —
 * everything {@see I18nCoverageSync} may need to mutate to bring the issue in line with a
 * {@see I18nCoverageDecision}. Never called at all in a dry run.
 */
interface I18nCoverageIssueExecutor
{
    /**
     * @param list<string> $labels
     */
    public function createIssue(string $title, string $body, array $labels): void;

    public function rewriteBody(int $issueNumber, string $body): void;

    public function addComment(int $issueNumber, string $comment): void;

    public function close(int $issueNumber): void;

    /**
     * The caller ({@see I18nCoverageSync}) only ever calls this after confirming via
     * {@see I18nCoverageIssueStateSource::hasLabel()} that the label is missing — this method
     * itself does not check.
     */
    public function createLabel(string $name, string $description): void;
}
