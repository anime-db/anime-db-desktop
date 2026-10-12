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

namespace App\Service\Notification\Kind;

/**
 * The subject of a scan notification: the scan run page (`/storage/{id}/scans/{run}`). The id packs
 * both numbers as "<storage>:<run>"; no journal row (or no storage id) gives no subject.
 */
final class ScanRunSubject
{
    public const string TYPE = 'storage_scan_run';

    /** @return array{subjectType: ?string, subjectId: ?string} */
    public static function of(?int $storageId, ?int $runId): array
    {
        if ($storageId === null || $runId === null) {
            return ['subjectType' => null, 'subjectId' => null];
        }

        return ['subjectType' => self::TYPE, 'subjectId' => $storageId.':'.$runId];
    }
}
