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
use App\Service\Plugin\LinkRulesProvider;
use App\Tests\Support\BuildsLinkRulesPlugins;
use App\Tests\Support\TemporaryDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SourceUrlNormalizerTest extends TestCase
{
    use BuildsLinkRulesPlugins;
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

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

    /**
     * @param array<string, array<string, string>> $plugins plugin id => rules
     */
    private function normalizerWith(array $plugins, ?LoggerInterface $logger = null): SourceUrlNormalizer
    {
        foreach ($plugins as $id => $rules) {
            $this->writeLinkRulesPlugin($id, $rules);
        }

        return new SourceUrlNormalizer(new LinkRulesProvider($this->linkRulesRegistry()), logger: $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testWithoutRulesTheResultIsTheCommonSteps(): void
    {
        $normalizer = $this->normalizerWith([]);

        $this->assertSame('https://example.org/a', $normalizer->normalize('http://www.Example.org/a/'));
    }

    public function testFirstMatchingRuleInsideAFileWins(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/a/(\d+)' => 'https://example.org/first/$1',
            'https://example\.org/a/(\d+)(?:/.*)?' => 'https://example.org/second/$1',
        ]]);

        $this->assertSame('https://example.org/first/1', $normalizer->normalize('https://example.org/a/1'));
        $this->assertSame('https://example.org/second/1', $normalizer->normalize('https://example.org/a/1/Name'));
    }

    public function testPluginWithLargerIdWinsOnTheSameSite(): void
    {
        $normalizer = $this->normalizerWith([
            'acme-a' => ['https://example\.org/a/(\d+)' => 'https://example.org/from-a/$1'],
            'acme-b' => ['https://example\.org/a/(\d+)' => 'https://example.org/from-b/$1'],
        ]);

        $this->assertSame('https://example.org/from-b/1', $normalizer->normalize('https://example.org/a/1'));
    }

    public function testOnlyOnePassIsMade(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/a/(\d+)' => 'https://example.org/b/$1',
            'https://example\.org/b/(\d+)' => 'https://example.org/c/$1',
        ]]);

        $this->assertSame('https://example.org/b/1', $normalizer->normalize('https://example.org/a/1'));
    }

    public function testRuleWrittenForSortedQueryMatchesAnyParameterOrder(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/show\?a=(\d+)&b=2' => 'https://example.org/show/$1',
        ]]);

        $this->assertSame('https://example.org/show/7', $normalizer->normalize('https://example.org/show?b=2&a=7'));
    }

    #[DataProvider('substitutionFormsProvider')]
    public function testBothSubstitutionFormsGiveTheSameResult(string $replacement): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => ['https://example\.org/a/(\d+)' => $replacement]]);

        $this->assertSame('https://example.org/b/1x', $normalizer->normalize('https://example.org/a/1'));
    }

    /** @return iterable<string, array{string}> */
    public static function substitutionFormsProvider(): iterable
    {
        yield 'dollar N' => ['https://example.org/b/$1x'];
        yield 'braced N' => ['https://example.org/b/${1}x'];
    }

    public function testAlternationDoesNotMatchASubstring(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => ['https://example\.org/a(?:)|https://example\.org/(b)' => 'https://example.org/hit/$1']]);

        $this->assertSame('https://example.org/hit/b', $normalizer->normalize('https://example.org/b'));
        $this->assertSame('https://example.org/b/more', $normalizer->normalize('https://example.org/b/more'));
        $this->assertSame('https://other.example/x/https://example.org/a', $normalizer->normalize('https://other.example/x/https://example.org/a'));
    }

    public function testResultThatIsNotAnAbsoluteHttpsLinkFallsThroughToTheNextRule(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/go(.*)' => 'https://$1',
            'https://example\.org/go(/?.*)' => 'https://example.org/ok$1',
        ]]);

        // The first rule yields "https://" without a host and is skipped.
        $this->assertSame('https://example.org/ok', $normalizer->normalize('https://example.org/go'));
    }

    public function testResultOfARuleGoesThroughTheCommonStepsAgain(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/a/(\d+)' => 'https://WWW.Example.ORG/b/$1/',
        ]]);

        $this->assertSame('https://example.org/b/1', $normalizer->normalize('https://example.org/a/1'));
    }

    public function testResultLongerThanTheLimitFallsBackToTheCommonSteps(): void
    {
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/a/(\d+)' => 'https://example.org/'.str_repeat('x', 1100).'/$1',
        ]]);

        $this->assertSame('https://example.org/a/1', $normalizer->normalize('https://example.org/a/1'));
    }

    public function testCatastrophicRuleIsSkippedWithAWarningAndTheNextRuleApplies(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $normalizer = $this->normalizerWith(['acme-rules' => [
            'https://example\.org/(a+)+(?:x|y)' => 'https://example.org/heavy/$1',
            'https://example\.org/(a+)c' => 'https://example.org/light/$1',
        ]], $logger);
        $before = ini_get('pcre.backtrack_limit');

        $result = $normalizer->normalize('https://example.org/'.str_repeat('a', 40).'c');

        $this->assertSame('https://example.org/light/'.str_repeat('a', 40), $result);
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
    }
}
