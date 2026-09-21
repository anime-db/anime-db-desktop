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

namespace App\Service\Backup;

/**
 * Lists the snapshots native/supervisor/migrations.js writes into getBackupsDir(), for the
 * /settings/backup snapshot list (issue #681). Read-only: creating a snapshot (createBackup()/
 * createPreImportBackup()), pruning routine ones (pruneOldBackups()) and restoring one
 * (restoreBackup()) all stay native-side — this only reads directory entries this PHP process
 * never writes, the same division BackupController's docblock already draws for export/import.
 */
final class BackupListService
{
    private const string PREIMPORT_PREFIX = 'data-preimport-';

    public function __construct(
        private readonly string $backupsDir,
    ) {
    }

    /**
     * @return list<BackupSnapshot> newest first; empty when the directory doesn't exist yet or
     *                              holds no recognizable snapshot (acceptance criterion 7) — a fresh profile with no backup
     *                              taken yet is not an error
     */
    public function list(): array
    {
        if (!is_dir($this->backupsDir)) {
            return [];
        }

        $entries = scandir($this->backupsDir);
        $snapshots = [];
        foreach ($entries === false ? [] : $entries as $file) {
            $snapshot = $this->toSnapshot($file);
            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        usort($snapshots, static fn (BackupSnapshot $a, BackupSnapshot $b): int => $b->createdAt <=> $a->createdAt);

        return $snapshots;
    }

    private function toSnapshot(string $file): ?BackupSnapshot
    {
        $isPreImport = str_starts_with($file, self::PREIMPORT_PREFIX);
        $pattern = $isPreImport ? '/^data-preimport-(\d{8})-(\d{6})\.db$/' : '/^data-.+-(\d{8})-(\d{6})\.db$/';

        if (preg_match($pattern, $file, $matches) !== 1) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat('Ymd-His', $matches[1].'-'.$matches[2]);
        if ($createdAt === false) {
            return null;
        }

        $size = filesize($this->backupsDir.\DIRECTORY_SEPARATOR.$file);
        if ($size === false) {
            return null;
        }

        return new BackupSnapshot($file, $createdAt, $size, $isPreImport);
    }
}
