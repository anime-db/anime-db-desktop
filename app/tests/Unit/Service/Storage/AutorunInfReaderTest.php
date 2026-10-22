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

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\AutorunInfReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutorunInfReaderTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function encodedLabels(): iterable
    {
        $text = "[AutoRun]\r\nicon=a.ico\r\nLabel = Аниме\r\n";

        yield 'utf-16 le bom' => ["\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')];
        yield 'utf-8 bom' => ["\xEF\xBB\xBF".$text];
        yield 'utf-8 without bom' => [$text];
        yield 'cp1251' => [mb_convert_encoding($text, 'Windows-1251', 'UTF-8')];
    }

    #[DataProvider('encodedLabels')]
    public function testReadsLabelInEveryEncoding(string $bytes): void
    {
        $this->assertSame('Аниме', (new AutorunInfReader())->getValue($bytes, 'autorun', 'label'));
    }

    public function testIgnoresOtherSectionsAndMissingKeys(): void
    {
        $bytes = "[Other]\nlabel=No\n[autorun]\nopen=x.exe\n";

        $this->assertNull((new AutorunInfReader())->getValue($bytes, 'AutoRun', 'label'));
        $this->assertSame('x.exe', (new AutorunInfReader())->getValue($bytes, 'AUTORUN', 'OPEN'));
    }
}
