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

namespace App\Tests\Unit\DomainEvent\Catalog;

use App\DomainEvent\Catalog\AnimeEventCoalescer;
use App\Event\Catalog\AnimeAdded;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use App\Event\Catalog\AnimeSnapshot;
use App\Event\Catalog\FieldChange;
use PHPUnit\Framework\TestCase;

final class AnimeEventCoalescerTest extends TestCase
{
    public function testChangedTakesBeforeOfTheFirstAndAfterOfTheLast(): void
    {
        $result = (new AnimeEventCoalescer())->coalesce([
            new AnimeChanged(1, ['title' => new FieldChange('a', 'b')]),
            new AnimeChanged(1, ['title' => new FieldChange('b', 'c'), 'notes' => new FieldChange(null, 'n')]),
        ]);

        self::assertCount(1, $result);
        self::assertInstanceOf(AnimeChanged::class, $result[0]);
        self::assertEquals(['title' => new FieldChange('a', 'c'), 'notes' => new FieldChange(null, 'n')], $result[0]->changes);
    }

    public function testFieldThatReturnedToItsOriginalValueDropsOut(): void
    {
        $result = (new AnimeEventCoalescer())->coalesce([
            new AnimeChanged(1, ['title' => new FieldChange('a', 'b')]),
            new AnimeChanged(1, ['title' => new FieldChange('b', 'a')]),
        ]);

        self::assertSame([], $result);
    }

    public function testAddedAndChangedGiveAdded(): void
    {
        $added = new AnimeAdded(1);
        $result = (new AnimeEventCoalescer())->coalesce([$added, new AnimeChanged(1, ['title' => new FieldChange('a', 'b')])]);

        self::assertSame([$added], $result);
    }

    public function testAddedAndRemovedGiveNothing(): void
    {
        $result = (new AnimeEventCoalescer())->coalesce([new AnimeAdded(1), new AnimeRemoved(1, new AnimeSnapshot([]))]);

        self::assertSame([], $result);
    }

    public function testChangedAndRemovedGiveRemoved(): void
    {
        $removed = new AnimeRemoved(1, new AnimeSnapshot([]));
        $result = (new AnimeEventCoalescer())->coalesce([new AnimeChanged(1, ['title' => new FieldChange('a', 'b')]), $removed]);

        self::assertSame([$removed], $result);
    }

    public function testEventsOfOtherRecordsAndOtherClassesAreKeptInOrder(): void
    {
        $other = new \stdClass();
        $first = new AnimeAdded(1);
        $second = new AnimeAdded(2);

        $result = (new AnimeEventCoalescer())->coalesce([$first, $other, $second, new AnimeChanged(2, ['title' => new FieldChange('a', 'b')])]);

        self::assertSame([$first, $other, $second], $result);
    }
}
