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

namespace App\Tests\Unit\Entity;

use App\Entity\AnimeName;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\TvAnime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimeNameTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $anime = new TvAnime();
        $name = new AnimeName($anime, 'Cowboy Bebop', 'en', AnimeNameRole::Official);

        $this->assertSame($anime, $name->anime);
        $this->assertSame('Cowboy Bebop', $name->name);
        $this->assertSame('en', $name->locale);
        $this->assertSame(AnimeNameRole::Official, $name->role);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function provideLocales(): iterable
    {
        yield 'already canonical' => ['ru', 'ru'];
        yield 'uppercase' => ['RU', 'ru'];
        yield 'regional subtag is stripped' => ['ru-RU', 'ru'];
        yield 'underscore separator' => ['ru_RU', 'ru'];
        yield 'three-letter subtag is kept' => ['jpn', 'jpn'];
        yield 'null stays null' => [null, null];
        yield 'not a language subtag becomes null' => ['russian', null];
        yield 'empty string becomes null' => ['', null];
    }

    #[DataProvider('provideLocales')]
    public function testConstructorNormalizesLocale(?string $given, ?string $expected): void
    {
        $anime = new TvAnime();
        $name = new AnimeName($anime, 'Cowboy Bebop', $given, AnimeNameRole::Synonym);

        $this->assertSame($expected, $name->locale);
    }
}
