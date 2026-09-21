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
 * One `data.db` snapshot from getBackupsDir() (native/supervisor/migrations.js), as listed by
 * {@see BackupListService} for the /settings/backup snapshot list (issue #681). `$name` is the
 * bare file name, not a full path — it is what the restore action sends back over IPC
 * (window.animeDb.backupRestoreStart()), resolved against the backups directory native-side.
 */
final class BackupSnapshot
{
    public function __construct(
        public readonly string $name,
        public readonly \DateTimeImmutable $createdAt,
        public readonly int $sizeBytes,
        public readonly bool $isPreImport,
    ) {
    }
}
