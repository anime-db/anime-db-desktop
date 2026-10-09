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

namespace App\Service\Storage\Scan;

/**
 * One row of the scan journal as read back. $items are the stored `scan.done` items (version 1,
 * without Updated ones) — empty when the row was loaded without them.
 */
final readonly class ScanRun
{
    /**
     * @param array<string, int>         $counts number of items per {@see ScanItemType} name, Updated included
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public int $id,
        public int $storageId,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ScanRunStatus $status,
        public ?string $errorMessage,
        public array $counts,
        public array $items,
    ) {
    }
}
