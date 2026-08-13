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

namespace App\Tests\Unit\Entity\ValueObject;

use App\Entity\ValueObject\Exception\InvalidRatingException;
use App\Entity\ValueObject\Rating;
use PHPUnit\Framework\TestCase;

final class RatingTest extends TestCase
{
    public function testAcceptsMinimumValue(): void
    {
        $rating = new Rating(1);

        $this->assertSame(1, $rating->value);
    }

    public function testAcceptsMaximumValue(): void
    {
        $rating = new Rating(5);

        $this->assertSame(5, $rating->value);
    }

    public function testRejectsValueBelowMinimum(): void
    {
        $this->expectException(InvalidRatingException::class);
        new Rating(0);
    }

    public function testRejectsValueAboveMaximum(): void
    {
        $this->expectException(InvalidRatingException::class);
        new Rating(6);
    }
}
