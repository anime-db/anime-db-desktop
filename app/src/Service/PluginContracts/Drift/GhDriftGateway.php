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

use App\Service\I18nCoverage\CodeownersSource;
use App\Service\I18nCoverage\Github\GhCodeownersSource;
use App\Service\I18nCoverage\Github\GhIssueExecutor;
use App\Service\I18nCoverage\Github\GhIssueStateSource;
use App\Service\I18nCoverage\I18nCoverageIssueExecutor;
use App\Service\I18nCoverage\I18nCoverageIssueStateSource;

final class GhDriftGateway implements DriftGateway
{
    public function stateSource(string $repo, string $pluginId, string $label): I18nCoverageIssueStateSource
    {
        return new GhIssueStateSource($repo, $pluginId, $label);
    }

    public function codeowners(string $repo): CodeownersSource
    {
        return new GhCodeownersSource($repo);
    }

    public function executor(string $repo): I18nCoverageIssueExecutor
    {
        return new GhIssueExecutor($repo);
    }
}
