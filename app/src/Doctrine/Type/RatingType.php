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

namespace App\Doctrine\Type;

use App\Doctrine\Type\Exception\InvalidRatingValueException;
use App\Entity\ValueObject\Rating;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps the Rating value object to a plain INTEGER column, the same way the DB schema
 * already stored the raw user_rating int before this VO was introduced.
 */
final class RatingType extends Type
{
    public const NAME = 'rating';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof Rating) {
            throw new InvalidRatingValueException(\sprintf('Expected %s, got %s.', Rating::class, get_debug_type($value)));
        }

        return $value->value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Rating
    {
        if ($value === null) {
            return null;
        }

        return new Rating((int) $value);
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
