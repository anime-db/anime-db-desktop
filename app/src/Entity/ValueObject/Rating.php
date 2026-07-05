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

namespace App\Entity\ValueObject;

use App\Entity\ValueObject\Exception\InvalidRatingException;

/**
 * User rating of an anime. There is no "not rated" value within Rating itself: absence
 * of a rating is expressed by Anime holding a null ?Rating, the same way it already
 * expresses other optional facts (datePremiere, dateEnd, cover, notes).
 */
final class Rating
{
    private const MIN = 1;
    private const MAX = 5;

    public readonly int $value;

    public function __construct(int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidRatingException(\sprintf('Rating must be between %d and %d, got %d.', self::MIN, self::MAX, $value));
        }

        $this->value = $value;
    }
}
