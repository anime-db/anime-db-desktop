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

namespace App\Tests\Unit\Twig;

use App\Entity\AnimeSource;
use App\Twig\DisplayUrlExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisplayUrlExtensionTest extends TestCase
{
    public function testDecodesHostAndPath(): void
    {
        $url = 'https://xn--e1afmkfd.xn--p1ai/%D1%81%D1%82%D1%80%D0%B0%D0%BD%D0%B8%D1%86%D0%B0';

        $this->assertSame('https://пример.рф/страница', DisplayUrlExtension::displayUrl($url));
        $this->assertSame('пример.рф', DisplayUrlExtension::displayHost('xn--e1afmkfd.xn--p1ai'));
    }

    public function testDecodesJapanesePath(): void
    {
        $this->assertSame(
            'https://ja.wikipedia.org/wiki/葬送のフリーレン',
            DisplayUrlExtension::displayUrl('https://ja.wikipedia.org/wiki/%E8%91%AC%E9%80%81%E3%81%AE%E3%83%95%E3%83%AA%E3%83%BC%E3%83%AC%E3%83%B3'),
        );
    }

    public function testKeepsAsciiEscapes(): void
    {
        $url = 'https://example.org/a%2Fb%20c?q=%26';

        $this->assertSame($url, DisplayUrlExtension::displayUrl($url));
    }

    public function testDecodesQueryAndFragment(): void
    {
        $this->assertSame(
            'https://example.org/?q=ё%26#я',
            DisplayUrlExtension::displayUrl('https://example.org/?q=%D1%91%26#%D1%8F'),
        );
    }

    public function testSuspiciousHostStaysPunycode(): void
    {
        // "аnidb.net" with a Cyrillic "а"
        $this->assertSame('xn--nidb-43d.net', DisplayUrlExtension::displayHost('xn--nidb-43d.net'));
        $this->assertSame('https://xn--nidb-43d.net/x', DisplayUrlExtension::displayUrl('https://xn--nidb-43d.net/x'));
    }

    public function testMixedScriptHostStaysPunycode(): void
    {
        $host = idn_to_ascii('пример.com', \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
        $this->assertIsString($host);

        $this->assertSame($host, DisplayUrlExtension::displayHost($host));
    }

    #[DataProvider('invalidSequences')]
    public function testInvalidSequencesStayAsIs(string $url): void
    {
        $this->assertSame($url, DisplayUrlExtension::displayUrl($url));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSequences(): iterable
    {
        yield 'lone high byte' => ['https://example.org/%FF'];
        yield 'truncated sequence' => ['https://example.org/%D1'];
        yield 'bad hex' => ['https://example.org/%ZZ%8'];
        yield 'overlong' => ['https://example.org/%C0%AF'];
        yield 'not a url' => ['not a url %D1%91'];
    }

    public function testValidCharacterNextToInvalidByteIsDecoded(): void
    {
        $this->assertSame('https://example.org/ё%FF', DisplayUrlExtension::displayUrl('https://example.org/%D1%91%FF'));
    }

    #[DataProvider('dangerousSequences')]
    public function testDangerousCharactersStayEncoded(string $escape): void
    {
        $url = 'https://example.org/a'.$escape.'b';

        $this->assertSame($url, DisplayUrlExtension::displayUrl($url));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousSequences(): iterable
    {
        yield 'RLO' => ['%E2%80%AE'];
        yield 'ZWSP' => ['%E2%80%8B'];
        yield 'NBSP' => ['%C2%A0'];
        yield 'LRM' => ['%E2%80%8E'];
        yield 'isolate' => ['%E2%81%A6'];
        yield 'BOM' => ['%EF%BB%BF'];
        yield 'C1 control' => ['%C2%85'];
    }

    public function testRoundTripThroughNormalizationGivesStoredForm(): void
    {
        foreach ([
            'https://xn--e1afmkfd.xn--p1ai/%D1%81%D1%82%D1%80%D0%B0%D0%BD%D0%B8%D1%86%D0%B0?q=%D1%91#%D1%8F',
            'https://ja.wikipedia.org/wiki/%E8%91%AC%E9%80%81%E3%81%AE%E3%83%95%E3%83%AA%E3%83%BC%E3%83%AC%E3%83%B3',
            'https://xn--nidb-43d.net/%E2%80%AE',
            'https://example.org/a%2Fb%20c?q=%26',
            'https://xn--strae-oqa.de/',
            'https://www.xn--e1afmkfd.xn--p1ai/',
            'https://example.org/%d1%91',
        ] as $stored) {
            $this->assertSame($stored, AnimeSource::normalizeUrl(DisplayUrlExtension::displayUrl($stored)), $stored);
        }
    }

    public function testAsciiSubdomainDoesNotHideIdnLabel(): void
    {
        $this->assertSame('www.пример.рф', DisplayUrlExtension::displayHost('www.xn--e1afmkfd.xn--p1ai'));
    }

    public function testLowerCaseEscapesStayEncoded(): void
    {
        $this->assertSame('https://example.org/%d1%91', DisplayUrlExtension::displayUrl('https://example.org/%d1%91'));
    }
}
