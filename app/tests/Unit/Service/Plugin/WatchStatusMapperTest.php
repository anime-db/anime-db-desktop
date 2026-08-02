<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Entity\Enum\WatchStatus;
use App\Service\Plugin\WatchStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchStatusMapperTest extends TestCase
{
    /** @return iterable<string, array{WatchStatus, SyncStatus}> */
    public static function statusPairs(): iterable
    {
        yield 'plan' => [WatchStatus::Plan, SyncStatus::Plan];
        yield 'watching' => [WatchStatus::Watching, SyncStatus::Watching];
        yield 'completed' => [WatchStatus::Completed, SyncStatus::Completed];
        yield 'dropped' => [WatchStatus::Dropped, SyncStatus::Dropped];
        yield 'on hold' => [WatchStatus::OnHold, SyncStatus::OnHold];
    }

    #[DataProvider('statusPairs')]
    public function testMapsEveryWatchStatusToItsMatchingSyncStatus(WatchStatus $watchStatus, SyncStatus $expected): void
    {
        $this->assertSame($expected, WatchStatusMapper::toSyncStatus($watchStatus));
    }

    #[DataProvider('statusPairs')]
    public function testMapsEverySyncStatusBackToItsMatchingWatchStatus(WatchStatus $expected, SyncStatus $status): void
    {
        $this->assertSame($expected, WatchStatusMapper::toWatchStatus($status));
    }
}
