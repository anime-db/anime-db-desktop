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

namespace App\Tests\Unit\Service;

use App\Entity\Enum\AnimeSortField;
use App\Entity\Enum\SortDirection;
use App\Service\AnimeListSortResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimeListSortResolverTest extends TestCase
{
    private AnimeListSortResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AnimeListSortResolver();
    }

    public function testDefaultsToDateUpdateDescendingWhenFieldIsMissing(): void
    {
        $sort = $this->resolver->resolve(null, null);

        $this->assertSame(AnimeSortField::DateUpdate, $sort->field);
        $this->assertSame(SortDirection::Desc, $sort->direction);
    }

    public function testDefaultsToDateUpdateDescendingWhenFieldIsNotWhitelisted(): void
    {
        $sort = $this->resolver->resolve('title; DROP TABLE anime;', 'asc');

        $this->assertSame(AnimeSortField::DateUpdate, $sort->field);
        $this->assertSame(SortDirection::Desc, $sort->direction);
    }

    /** @return iterable<string, array{string, AnimeSortField}> */
    public static function whitelistedFields(): iterable
    {
        yield 'name' => ['name', AnimeSortField::Name];
        yield 'date_update' => ['date_update', AnimeSortField::DateUpdate];
        yield 'user_rating' => ['user_rating', AnimeSortField::UserRating];
        yield 'date_premiere' => ['date_premiere', AnimeSortField::DatePremiere];
        yield 'date_end' => ['date_end', AnimeSortField::DateEnd];
    }

    #[DataProvider('whitelistedFields')]
    public function testAcceptsEveryWhitelistedField(string $raw, AnimeSortField $expected): void
    {
        $sort = $this->resolver->resolve($raw, 'asc');

        $this->assertSame($expected, $sort->field);
        $this->assertSame(SortDirection::Asc, $sort->direction);
    }

    public function testFallsBackToDescendingWhenDirectionIsInvalid(): void
    {
        $sort = $this->resolver->resolve('name', 'sideways');

        $this->assertSame(AnimeSortField::Name, $sort->field);
        $this->assertSame(SortDirection::Desc, $sort->direction);
    }
}
