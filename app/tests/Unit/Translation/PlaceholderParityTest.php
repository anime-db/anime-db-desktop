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

namespace App\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;

final class PlaceholderParityTest extends TestCase
{
    public function testDiffIsNullWhenPlaceholderSetsMatch(): void
    {
        $this->assertNull(PlaceholderParity::diff('Error: %detail%', 'Ошибка: %detail%'));
    }

    public function testDiffIgnoresPlaceholderOrder(): void
    {
        $this->assertNull(PlaceholderParity::diff('%a% and %b%', '%b% and %a%'));
    }

    public function testDiffReportsALostPlaceholder(): void
    {
        $diff = PlaceholderParity::diff('Error: %detail%', 'Ошибка: ');

        $this->assertSame(['missing' => ['detail'], 'extra' => []], $diff);
    }

    public function testDiffReportsARenamedPlaceholderInBothDirections(): void
    {
        $diff = PlaceholderParity::diff('Error: %detail%', 'Ошибка: %reason%');

        $this->assertSame(['missing' => ['detail'], 'extra' => ['reason']], $diff);
    }

    public function testExtractDeduplicatesRepeatedPlaceholders(): void
    {
        $this->assertSame(['total'], PlaceholderParity::extract('%total% of %total%'));
    }

    public function testFindForbiddenCharactersDetectsCurlyBraces(): void
    {
        $this->assertSame(['{', '}'], PlaceholderParity::findForbiddenCharacters('Hello {name}'));
    }

    public function testFindForbiddenCharactersDetectsPipe(): void
    {
        $this->assertSame(['|'], PlaceholderParity::findForbiddenCharacters('one item|many items'));
    }

    public function testFindForbiddenCharactersIsEmptyForAPlainPlaceholder(): void
    {
        $this->assertSame([], PlaceholderParity::findForbiddenCharacters('Error: %detail%'));
    }
}
