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

namespace App\Tests\Unit\Service\Market;

use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotReadiness;
use PHPUnit\Framework\TestCase;

final class MarketSnapshotReadinessTest extends TestCase
{
    public function testIsNotReadyWhenTheSnapshotIsMissing(): void
    {
        $this->assertFalse((new MarketSnapshotReadiness())->isReady(null, '2.5.0'));
    }

    public function testIsNotReadyWhenTheSnapshotWasBuiltForAnotherCoreVersion(): void
    {
        $snapshot = new MarketSnapshot('1.0.0', 1, [], []);

        $this->assertFalse((new MarketSnapshotReadiness())->isReady($snapshot, '2.5.0'));
    }

    public function testIsReadyWhenTheSnapshotMatchesTheCoreVersion(): void
    {
        $snapshot = new MarketSnapshot('2.5.0', 1, [], []);

        $this->assertTrue((new MarketSnapshotReadiness())->isReady($snapshot, '2.5.0'));
    }
}
