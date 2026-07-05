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

namespace App\Tests\Unit\Entity;

use App\Entity\Anime;
use App\Entity\AnimeName;
use App\Entity\Enum\AnimeNameType;
use PHPUnit\Framework\TestCase;

final class AnimeNameTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $anime = new Anime();
        $name = new AnimeName($anime, 'Cowboy Bebop', AnimeNameType::English);

        $this->assertSame($anime, $name->anime);
        $this->assertSame('Cowboy Bebop', $name->name);
        $this->assertSame(AnimeNameType::English, $name->type);
    }
}
