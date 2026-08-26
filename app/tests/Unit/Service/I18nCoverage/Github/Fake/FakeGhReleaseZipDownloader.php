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

namespace App\Tests\Unit\Service\I18nCoverage\Github\Fake;

use App\Service\I18nCoverage\Github\GhReleaseZipDownloader;

/**
 * Hands back the one pre-built zip path it was constructed with, standing in for "the plugin's
 * latest release asset" without any `gh` or network involved. Records the plugin id it was asked
 * for so a test can assert the caller requested the latest release of *this* plugin, not a
 * working-tree checkout or some other version.
 */
final class FakeGhReleaseZipDownloader implements GhReleaseZipDownloader
{
    public ?string $requestedPluginId = null;

    public function __construct(private readonly string $zipPath)
    {
    }

    public function downloadLatestReleaseZip(string $pluginId): string
    {
        $this->requestedPluginId = $pluginId;

        return $this->zipPath;
    }
}
