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

namespace App\Service\I18nCoverage\Github;

use App\Service\I18nCoverage\I18nCoverageIssueExecutor;

/**
 * Real {@see I18nCoverageIssueExecutor}, backed by `gh issue`/`gh label` against the plugin's own
 * repository. `--force` on `gh label create` makes it a create-or-update rather than erroring on
 * an existing label, but {@see \App\Service\I18nCoverage\I18nCoverageSync} still gates the call
 * behind {@see \App\Service\I18nCoverage\I18nCoverageIssueStateSource::hasLabel()} — the point of
 * that check is not avoiding a `gh` error, it is not touching a label (and its description) that
 * was already there.
 */
final class GhIssueExecutor implements I18nCoverageIssueExecutor
{
    public function __construct(
        private readonly string $repo,
        private readonly GhCommand $gh = new GhCommand(),
    ) {
    }

    public function createIssue(string $title, string $body, array $labels): void
    {
        $arguments = ['issue', 'create', '--repo', $this->repo, '--title', $title, '--body', $body];
        foreach ($labels as $label) {
            $arguments[] = '--label';
            $arguments[] = $label;
        }

        $this->gh->run($arguments);
    }

    public function rewriteBody(int $issueNumber, string $body): void
    {
        $this->gh->run(['issue', 'edit', (string) $issueNumber, '--repo', $this->repo, '--body', $body]);
    }

    public function addComment(int $issueNumber, string $comment): void
    {
        $this->gh->run(['issue', 'comment', (string) $issueNumber, '--repo', $this->repo, '--body', $comment]);
    }

    public function close(int $issueNumber): void
    {
        $this->gh->run(['issue', 'close', (string) $issueNumber, '--repo', $this->repo]);
    }

    public function createLabel(string $name, string $description): void
    {
        $this->gh->run(['label', 'create', $name, '--repo', $this->repo, '--description', $description, '--force']);
    }
}
