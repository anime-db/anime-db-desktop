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

namespace App\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps \DateTimeImmutable to a plain Unix timestamp (INTEGER column) instead of a
 * DATE/DATETIME string. SQLite has no dedicated date/time storage, so Doctrine's
 * built-in date types are stored as timezone-less text, which is a source of
 * off-by-one-day/hour bugs. An integer Unix timestamp is unambiguous.
 */
final class UnixTimestampType extends Type
{
    public const NAME = 'unix_timestamp';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(\sprintf('Expected %s, got %s.', \DateTimeImmutable::class, get_debug_type($value)));
        }

        return $value->getTimestamp();
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $value);
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
