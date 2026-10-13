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

use App\Entity\AnimeSource;
use App\Entity\TvAnime;
use PHPUnit\Framework\TestCase;

final class AnimeSourceTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $anime = new TvAnime();
        $source = new AnimeSource($anime, 'https://shikimori.one/animes/1');

        $this->assertSame($anime, $source->anime);
        $this->assertSame('https://shikimori.one/animes/1', $source->url);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urlProvider(): iterable
    {
        yield 'https' => ['https://example.org/a', true];
        yield 'http' => ['http://example.org/a', true];
        yield 'ftp' => ['ftp://example.org/a', false];
        yield 'relative' => ['/a', false];
        yield 'space' => ['https://example.org/a b', false];
        yield 'non-ascii raw' => ['https://ru.wikipedia.org/wiki/Блич', false];
        yield 'upper-case scheme' => ['HTTPS://example.org/a', false];
        yield 'max length' => ['https://example.org/'.str_repeat('a', 492), true];
        yield 'over max length' => ['https://example.org/'.str_repeat('a', 493), false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urlProvider')]
    public function testIsValidUrl(string $url, bool $valid): void
    {
        $this->assertSame($valid, AnimeSource::isValidUrl($url));
    }

    public function testKeyIgnoresSchemeWwwCaseAndTrailingSlash(): void
    {
        $this->assertSame(AnimeSource::key('https://example.org/x'), AnimeSource::key('http://www.Example.org/x/'));
        $this->assertNotSame(AnimeSource::key('https://a.org/1'), AnimeSource::key('https://b.org/1'));
    }

    public function testAnimeAddSourceIsIdempotentByKey(): void
    {
        $anime = new TvAnime();
        $anime->addSource('https://example.org/x')->addSource('http://www.example.org/x/');

        $this->assertCount(1, $anime->getSources());
    }

    public function testNormalizeUrlEncodesNonAsciiAndIsIdempotent(): void
    {
        $encoded = 'https://ja.wikipedia.org/wiki/%E8%91%AC%E9%80%81%E3%81%AE%E3%83%95%E3%83%AA%E3%83%BC%E3%83%AC%E3%83%B3';
        $raw = AnimeSource::normalizeUrl('https://ja.wikipedia.org/wiki/葬送のフリーレン');

        $this->assertSame($encoded, $raw);
        $this->assertTrue(AnimeSource::isValidUrl($raw));
        $this->assertSame($encoded, AnimeSource::normalizeUrl($encoded));
        $this->assertSame(AnimeSource::key($encoded), AnimeSource::key($raw));
    }

    public function testNormalizeUrlKeepsSpaceInvalid(): void
    {
        $this->assertFalse(AnimeSource::isValidUrl(AnimeSource::normalizeUrl('https://example.org/a b/ё')));
    }

    public function testNormalizeUrlConvertsIdnHost(): void
    {
        $this->assertSame('https://xn--e1afmkfd.xn--p1ai/a', AnimeSource::normalizeUrl('https://пример.рф/a'));
    }

    public function testNormalizeUrlMakesCyrillicDomainCaseInsensitive(): void
    {
        $expected = 'https://xn--e1afmkfd.xn--p1ai/%D1%81%D1%82%D1%80%D0%B0%D0%BD%D0%B8%D1%86%D0%B0';
        $upper = AnimeSource::normalizeUrl('https://ПРИМЕР.РФ/страница');
        $lower = AnimeSource::normalizeUrl('https://пример.рф/страница');

        $this->assertSame($expected, $upper);
        $this->assertSame($expected, $lower);
        $this->assertTrue(AnimeSource::isValidUrl($upper));
        $this->assertTrue(AnimeSource::isValidUrl($lower));
        $this->assertSame(AnimeSource::key($upper), AnimeSource::key($lower));
    }

    public function testNormalizeUrlLowercasesTheScheme(): void
    {
        $url = AnimeSource::normalizeUrl('HTTPS://example.org/a');

        $this->assertSame('https://example.org/a', $url);
        $this->assertTrue(AnimeSource::isValidUrl($url));
        $this->assertSame('http://example.org/A', AnimeSource::normalizeUrl('HtTp://example.org/A'));
    }

    public function testNormalizeUrlKeepsThePortOfAnIdnHost(): void
    {
        $this->assertSame('https://xn--j1ail.xn--p1ai:8080/', AnimeSource::normalizeUrl('https://кто.рф:8080/'));
    }
}
