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

use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\I18nCoverage\I18nCoverageIssueStateSource;

/**
 * Real {@see I18nCoverageIssueStateSource}, backed by `gh issue list`/`gh label list` against the
 * plugin's own repository. `i18n-coverage` + `<plugin-id>` together identify "the" tracking issue
 * — both labels are always searched for together, matching how the label is meant to be used.
 */
final class GhIssueStateSource implements I18nCoverageIssueStateSource
{
    private const string COVERAGE_LABEL = 'i18n-coverage';

    public function __construct(
        private readonly string $repo,
        private readonly string $pluginId,
        private readonly GhCommand $gh = new GhCommand(),
    ) {
    }

    public function currentState(): I18nCoverageIssueSnapshot
    {
        $json = $this->gh->run([
            'issue', 'list',
            '--repo', $this->repo,
            '--state', 'open',
            '--label', self::COVERAGE_LABEL,
            '--label', $this->pluginId,
            '--json', 'number,body',
            '--limit', '1',
        ]);

        /** @var list<array{number: int, body: string}> $issues */
        $issues = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if ($issues === []) {
            return I18nCoverageIssueSnapshot::none();
        }

        return I18nCoverageIssueSnapshot::open($issues[0]['number'], $issues[0]['body']);
    }

    public function hasLabel(string $label): bool
    {
        $json = $this->gh->run(['label', 'list', '--repo', $this->repo, '--json', 'name', '--limit', '1000']);

        /** @var list<array{name: string}> $labels */
        $labels = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        foreach ($labels as $entry) {
            if ($entry['name'] === $label) {
                return true;
            }
        }

        return false;
    }
}
