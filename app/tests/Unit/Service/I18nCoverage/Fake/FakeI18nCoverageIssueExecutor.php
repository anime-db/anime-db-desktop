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

namespace App\Tests\Unit\Service\I18nCoverage\Fake;

use App\Service\I18nCoverage\I18nCoverageIssueExecutor;

/**
 * Records every call it receives instead of acting on it, so a test can assert exactly which
 * side effects {@see \App\Service\I18nCoverage\I18nCoverageSync} triggered — and, just as
 * importantly, which it did not (a dry run must leave every one of these lists empty).
 */
final class FakeI18nCoverageIssueExecutor implements I18nCoverageIssueExecutor
{
    /** @var list<array{title: string, body: string, labels: list<string>}> */
    public array $createdIssues = [];

    /** @var list<array{number: int, body: string}> */
    public array $rewrittenBodies = [];

    /** @var list<array{number: int, comment: string}> */
    public array $comments = [];

    /** @var list<int> */
    public array $closed = [];

    /** @var list<array{name: string, description: string}> */
    public array $createdLabels = [];

    public function createIssue(string $title, string $body, array $labels): void
    {
        $this->createdIssues[] = ['title' => $title, 'body' => $body, 'labels' => $labels];
    }

    public function rewriteBody(int $issueNumber, string $body): void
    {
        $this->rewrittenBodies[] = ['number' => $issueNumber, 'body' => $body];
    }

    public function addComment(int $issueNumber, string $comment): void
    {
        $this->comments[] = ['number' => $issueNumber, 'comment' => $comment];
    }

    public function close(int $issueNumber): void
    {
        $this->closed[] = $issueNumber;
    }

    public function createLabel(string $name, string $description): void
    {
        $this->createdLabels[] = ['name' => $name, 'description' => $description];
    }
}
