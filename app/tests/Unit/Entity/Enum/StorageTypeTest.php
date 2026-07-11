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

namespace App\Tests\Unit\Entity\Enum;

use App\Entity\Enum\StorageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTypeTest extends TestCase
{
    /** @return iterable<string, array{StorageType, bool}> */
    public static function types(): iterable
    {
        yield 'folder' => [StorageType::Folder, true];
        yield 'external' => [StorageType::External, true];
        yield 'external-r' => [StorageType::ExternalR, false];
        yield 'video' => [StorageType::Video, false];
    }

    #[DataProvider('types')]
    public function testIsWritable(StorageType $type, bool $expected): void
    {
        $this->assertSame($expected, $type->isWritable());
    }
}
