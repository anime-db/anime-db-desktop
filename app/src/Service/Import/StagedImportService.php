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
 *
 * {@see self::readMarker()} is also the single definition of marker validity the startup decision
 * step (native/supervisor/staged-import.js, issue #706) relies on — it runs `bin/console
 * app:import:staged-status` ({@see \App\Command\ImportStagedStatusCommand}), which calls this same
 * method, rather than re-parsing `import.json` with its own, possibly stricter rules. A marker
 * this method rejects must never be shown by the banner either, and vice versa.
 */
final class StagedImportService
{
    private const string MARKER_FILENAME = 'import.json';

    /**
     * The only `markerVersion` {@see self::readMarker()} accepts. {@see CatalogStageService}
     * writes this same constant rather than keeping its own copy, so there is exactly one place
     * that decides what a "current" marker looks like.
     */
    public const int SUPPORTED_MARKER_VERSION = 1;

    /**
     * @var list<string>
     */
    private const array KNOWN_REJECTION_REASONS = [
        'invalid_marker',
        'incompatible_schema',
        'user_declined',
        'import_rolled_back',
    ];

    public function __construct(
        private readonly string $importStagingDir,
        private readonly string $importRejectionPath,
    ) {
    }

    /**
     * Returns null whenever the staging directory is absent or its marker can't be trusted
     * (missing, unreadable, malformed JSON, missing/malformed fields, or an unknown
     * `markerVersion`) — the banner simply does not show rather than surfacing a broken staging
     * state as an error (acceptance criterion 6).
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
        if (!\is_array($data) || ($data['markerVersion'] ?? null) !== self::SUPPORTED_MARKER_VERSION) {
            return null;
        }
        if (!\is_string($data['stagedAt'] ?? null) || !\is_string($data['sourceArchive'] ?? null)) {
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

    /**
     * Reads back the reason native/supervisor/staged-import.js (issue #706) recorded the last time
     * it rejected a staged import, so /settings/backup can still tell the user why after the
     * staging directory it would otherwise have pointed to is already gone. Returns null when
     * nothing was rejected, or when the file is missing, unreadable, malformed, or names a reason
     * this build does not recognize — the page simply shows no notice rather than a broken one.
     */
    public function readRejectionReason(): ?string
    {
        if (!is_file($this->importRejectionPath)) {
            return null;
        }

        $contents = file_get_contents($this->importRejectionPath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        $reason = \is_array($data) ? ($data['reason'] ?? null) : null;

        return \is_string($reason) && \in_array($reason, self::KNOWN_REJECTION_REASONS, true) ? $reason : null;
    }
}
