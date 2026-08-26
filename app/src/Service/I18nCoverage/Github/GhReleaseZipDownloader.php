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

/**
 * Locates and downloads a plugin's latest *released* `plugin.zip` asset — kept as its own small
 * interface (rather than folded straight into {@see GhPluginReleaseTranslationKeysSource}) so a
 * test can substitute a fake asset without also faking zip parsing, and so the "released, never
 * working-tree" property is a fact about this one narrow seam rather than about the whole
 * key-reading class.
 */
interface GhReleaseZipDownloader
{
    /**
     * @return string local filesystem path to a downloaded `plugin.zip`
     */
    public function downloadLatestReleaseZip(string $pluginId): string;
}
