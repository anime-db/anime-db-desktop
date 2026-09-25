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

use App\Service\I18nCoverage\AmbiguousIssueStateException;
use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\I18nCoverage\I18nCoverageIssueStateSource;

/**
 * Real {@see I18nCoverageIssueStateSource}, backed by the REST issues list and `gh label list`
 * against the plugin's own repository. A tracking label (`i18n-coverage` by default) + `<plugin-id>`
 * together identify "the" tracking issue — both labels are always searched for together.
 */
final class GhIssueStateSource implements I18nCoverageIssueStateSource
{
    public const string DEFAULT_LABEL = 'i18n-coverage';

    public function __construct(
        private readonly string $repo,
        private readonly string $pluginId,
        private readonly string $label = self::DEFAULT_LABEL,
        private readonly GhCommand $gh = new GhCommand(),
    ) {
    }

    /**
     * Goes through the REST issues list, which reflects the current state; `gh issue list` uses the
     * search index, which lags behind and would hide an issue created moments ago.
     *
     * @throws AmbiguousIssueStateException when more than one open issue carries both labels
     */
    public function currentState(): I18nCoverageIssueSnapshot
    {
        $json = $this->gh->run([
            'api',
            \sprintf(
                'repos/%s/issues?labels=%s&state=open&per_page=100',
                $this->repo,
                rawurlencode($this->label).','.rawurlencode($this->pluginId),
            ),
        ]);

        /** @var list<array{number: int, body?: string|null, pull_request?: mixed}> $entries */
        $entries = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $issues = array_values(array_filter($entries, static fn (array $entry): bool => !isset($entry['pull_request'])));

        if ($issues === []) {
            return I18nCoverageIssueSnapshot::none();
        }

        if (\count($issues) > 1) {
            throw new AmbiguousIssueStateException(\sprintf('More than one open issue carries the labels "%s" and "%s" in %s.', $this->label, $this->pluginId, $this->repo));
        }

        return I18nCoverageIssueSnapshot::open($issues[0]['number'], $issues[0]['body'] ?? '');
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
