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
use App\Entity\Label;
use PHPUnit\Framework\TestCase;

final class LabelTest extends TestCase
{
    public function testRenameSetsName(): void
    {
        $label = new Label();
        $label->rename('favorite');

        $this->assertSame('favorite', $label->name);
    }

    public function testRenameTrimsName(): void
    {
        $label = new Label();
        $label->rename('  favorite  ');

        $this->assertSame('favorite', $label->name);
    }

    public function testRenameRejectsEmptyName(): void
    {
        $label = new Label();

        $this->expectException(InvalidNameException::class);
        $label->rename('   ');
    }

    public function testGetAnimesDefaultsToEmpty(): void
    {
        $label = new Label();

        $this->assertCount(0, $label->getAnimes());
    }

    public function testGetAnimesReflectsAnimeSideAssociation(): void
    {
        $label = new Label();
        $anime = new Anime();
        $anime->addLabel($label);

        $this->assertTrue($label->getAnimes()->contains($anime));
    }
}
