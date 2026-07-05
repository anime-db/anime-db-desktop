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
use App\Entity\Exception\InvalidNameException;
use App\Entity\Studio;
use PHPUnit\Framework\TestCase;

final class StudioTest extends TestCase
{
    public function testRenameSetsName(): void
    {
        $studio = new Studio();
        $studio->rename('Sunrise');

        $this->assertSame('Sunrise', $studio->name);
    }

    public function testRenameTrimsName(): void
    {
        $studio = new Studio();
        $studio->rename('  Sunrise  ');

        $this->assertSame('Sunrise', $studio->name);
    }

    public function testRenameRejectsEmptyName(): void
    {
        $studio = new Studio();

        $this->expectException(InvalidNameException::class);
        $studio->rename('   ');
    }

    public function testIsRemovableWhenNoAnimeIsLinked(): void
    {
        $studio = new Studio();

        $this->assertTrue($studio->isRemovable());
    }

    public function testIsNotRemovableWhenAnimeIsLinked(): void
    {
        $studio = new Studio();
        $anime = new Anime();
        $anime->addStudio($studio);

        $this->assertFalse($studio->isRemovable());
    }
}
