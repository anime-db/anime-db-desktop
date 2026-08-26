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
 * Source of a single translation plugin's own key set for {@see I18nCoverageIssueDecider}.
 *
 * The real implementation ({@see Github\GhPluginReleaseTranslationKeysSource}) reads this from
 * the plugin's latest *released* asset, deliberately never from a monorepo working tree — a key
 * added on `master` but not yet released is not something any user can install, so treating it as
 * "covered" would close an issue no user-installable version actually fixes.
 */
interface PluginTranslationKeysSource
{
    /**
     * @return list<string> flattened dot-notation keys the plugin's own catalog(s) define
     */
    public function keys(): array;
}
