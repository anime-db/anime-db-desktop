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

namespace App\Service\Import;

use App\Service\Plugin\PluginDirectoryRemover;

/**
 * Reads and cancels the catalog import {@see CatalogStageService} staged (issue #669), for the
 * /settings/backup pending-import banner (issue #671). Deliberately does not touch `data.db` or
 * apply anything — this is only the "still waiting to restart, or changed your mind" side of the
 * staged import, not the apply step a separate, not-yet-built supervisor task owns.
 */
final class StagedImportService
{
    private const string MARKER_FILENAME = 'import.json';

    public function __construct(
        private readonly string $importStagingDir,
    ) {
    }

    /**
     * Returns null whenever the staging directory is absent or its marker can't be trusted
     * (missing, unreadable, malformed JSON, or missing/malformed fields) — the banner simply
     * does not show rather than surfacing a broken staging state as an error (acceptance
     * criterion 6).
     */
    public function readMarker(): ?StagedImportMarker
    {
        $markerPath = $this->importStagingDir.\DIRECTORY_SEPARATOR.self::MARKER_FILENAME;

        if (!is_file($markerPath)) {
            return null;
        }

        $contents = file_get_contents($markerPath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!\is_array($data) || !\is_string($data['stagedAt'] ?? null) || !\is_string($data['sourceArchive'] ?? null)) {
            return null;
        }

        try {
            $stagedAt = new \DateTimeImmutable($data['stagedAt']);
        } catch (\Exception) {
            return null;
        }

        return new StagedImportMarker($stagedAt, $data['sourceArchive']);
    }

    public function cancel(): void
    {
        PluginDirectoryRemover::remove($this->importStagingDir);
    }
}
