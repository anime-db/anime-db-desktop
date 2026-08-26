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
 * Read side of a plugin's `i18n-coverage` tracking issue and its `<plugin-id>` label, kept
 * separate from {@see I18nCoverageIssueExecutor} (the write side) so a dry run can use the real
 * state source while never touching the write side at all.
 */
interface I18nCoverageIssueStateSource
{
    /**
     * @return I18nCoverageIssueSnapshot the single open issue carrying both the `i18n-coverage`
     *                                   and `<plugin-id>` labels, or {@see I18nCoverageIssueSnapshot::none()}
     *                                   when there is none
     */
    public function currentState(): I18nCoverageIssueSnapshot;

    public function hasLabel(string $label): bool;
}
