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

namespace App\Tests\Unit\Service\PluginContracts\Drift\Fake;

use App\Service\I18nCoverage\AmbiguousIssueStateException;
use App\Service\I18nCoverage\CodeownersSource;
use App\Service\I18nCoverage\I18nCoverageIssueExecutor;
use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\I18nCoverage\I18nCoverageIssueStateSource;
use App\Service\PluginContracts\Drift\DriftGateway;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeCodeownersSource;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeI18nCoverageIssueExecutor;

final class FakeDriftGateway implements DriftGateway
{
    public FakeI18nCoverageIssueExecutor $executor;

    /** @var list<string> plugin ids whose state was read */
    public array $stateReads = [];

    /**
     * @param array<string, I18nCoverageIssueSnapshot|'ambiguous'|'boom'> $issues
     * @param list<string>                                                $labels
     */
    public function __construct(
        private readonly array $issues = [],
        private readonly array $labels = ['plugin-contracts-drift'],
        private readonly string $codeowners = "/plugins/animedb-shikimori/ @maintainer\n",
    ) {
        $this->executor = new FakeI18nCoverageIssueExecutor();
    }

    public function stateSource(string $repo, string $pluginId, string $label): I18nCoverageIssueStateSource
    {
        $gateway = $this;
        $issue = $this->issues[$pluginId] ?? I18nCoverageIssueSnapshot::none();
        $labels = $this->labels;

        return new class($gateway, $pluginId, $issue, $labels) implements I18nCoverageIssueStateSource {
            /**
             * @param list<string> $labels
             */
            public function __construct(
                private readonly FakeDriftGateway $gateway,
                private readonly string $pluginId,
                private readonly I18nCoverageIssueSnapshot|string $issue,
                private readonly array $labels,
            ) {
            }

            public function currentState(): I18nCoverageIssueSnapshot
            {
                $this->gateway->stateReads[] = $this->pluginId;

                if ($this->issue === 'ambiguous') {
                    throw new AmbiguousIssueStateException('two open issues');
                }
                if (\is_string($this->issue)) {
                    throw new \RuntimeException('gh is down');
                }

                return $this->issue;
            }

            public function hasLabel(string $label): bool
            {
                return \in_array($label, $this->labels, true);
            }
        };
    }

    public function codeowners(string $repo): CodeownersSource
    {
        return new FakeCodeownersSource($this->codeowners);
    }

    public function executor(string $repo): I18nCoverageIssueExecutor
    {
        return $this->executor;
    }

    public function writes(): int
    {
        return \count($this->executor->createdIssues) + \count($this->executor->rewrittenBodies)
            + \count($this->executor->comments) + \count($this->executor->closed) + \count($this->executor->createdLabels);
    }
}
