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

use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\I18nCoverage\I18nCoverageIssueStateSource;

final class FakeI18nCoverageIssueStateSource implements I18nCoverageIssueStateSource
{
    /**
     * @param list<string> $existingLabels
     */
    public function __construct(
        private readonly I18nCoverageIssueSnapshot $snapshot,
        private readonly array $existingLabels = [],
    ) {
    }

    public function currentState(): I18nCoverageIssueSnapshot
    {
        return $this->snapshot;
    }

    public function hasLabel(string $label): bool
    {
        return \in_array($label, $this->existingLabels, true);
    }
}
