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

namespace App\Tests\Unit\Service\Catalog;

use App\Entity\SourceUrl;
use App\Service\Catalog\SourceUrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceUrlNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function commonStepsProvider(): iterable
    {
        yield 'http to https' => ['http://example.org/a', 'https://example.org/a'];
        yield 'host lower-cased' => ['https://Example.ORG/A', 'https://example.org/A'];
        yield 'idn to punycode' => ['https://пример.рф/a', 'https://xn--e1afmkfd.xn--p1ai/a'];
        yield 'www stripped' => ['https://www.example.org/a', 'https://example.org/a'];
        yield 'default port 443' => ['https://example.org:443/a', 'https://example.org/a'];
        yield 'default port 80' => ['http://example.org:80/a', 'https://example.org/a'];
        yield 'custom port kept' => ['https://example.org:8080/a', 'https://example.org:8080/a'];
        yield 'fragment dropped' => ['https://example.org/a#top', 'https://example.org/a'];
        yield 'fragment dropped after query' => ['https://example.org/a?x=1#top', 'https://example.org/a?x=1'];
        yield 'trailing slash stripped' => ['https://example.org/a/', 'https://example.org/a'];
        yield 'root kept' => ['https://example.org/', 'https://example.org/'];
        yield 'empty path is the root' => ['https://example.org', 'https://example.org/'];
        yield 'query parameters sorted, values untouched' => ['https://example.org/a?b=2&a=1&c=', 'https://example.org/a?a=1&b=2&c='];
        yield 'stable sort keeps equal names in order' => ['https://example.org/a?a=2&a=1', 'https://example.org/a?a=2&a=1'];
        yield 'stable sort with other names between' => ['https://example.org/a?b=1&a=2&b=0&a=1', 'https://example.org/a?a=2&a=1&b=1&b=0'];
        yield 'unreserved decoded' => ['https://example.org/%61%7E', 'https://example.org/a~'];
        yield 'reserved stays encoded, hex upper-cased' => ['https://example.org/a%2fb', 'https://example.org/a%2Fb'];
        yield 'stray percent is encoded' => ['https://example.org/100%', 'https://example.org/100%25'];
        yield 'unparseable is kept as is' => ['not a url', 'not a url'];
    }

    #[DataProvider('commonStepsProvider')]
    public function testCommonSteps(string $url, string $expected): void
    {
        $this->assertSame($expected, SourceUrl::common($url));
        $this->assertSame($expected, (new SourceUrlNormalizer())->normalize($url));
    }

    public function testEncodedAndRawCyrillicGiveTheSameResult(): void
    {
        $expected = 'https://ru.wikipedia.org/wiki/%D0%9C';

        $this->assertSame($expected, SourceUrl::common('https://ru.wikipedia.org/wiki/%d0%9c'));
        $this->assertSame($expected, SourceUrl::common('https://ru.wikipedia.org/wiki/%D0%9C'));
        $this->assertSame($expected, SourceUrl::common('https://ru.wikipedia.org/wiki/М'));
    }

    public function testQueryOrderDoesNotChangeTheResult(): void
    {
        $this->assertSame(
            SourceUrl::common('https://example.org/a?x=1&y=2&z=%D0%9C'),
            SourceUrl::common('https://example.org/a?z=%d0%9c&y=2&x=1'),
        );
    }

    public function testIdempotent(): void
    {
        $once = SourceUrl::common('http://www.Example.org:80/a%2fb/?b=1&a=М#x');

        $this->assertSame($once, SourceUrl::common($once));
    }

    public function testOverlongResultFallsBackToTheCommonSteps(): void
    {
        $url = 'https://example.org/'.str_repeat('a', 1100);

        $this->assertSame(SourceUrl::common($url), (new SourceUrlNormalizer())->normalize($url));
    }
}
