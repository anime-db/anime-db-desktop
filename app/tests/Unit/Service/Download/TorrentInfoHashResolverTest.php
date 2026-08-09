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

namespace App\Tests\Unit\Service\Download;

use App\Service\Download\TorrentInfoHashResolver;
use App\Service\Exception\InvalidTorrentFileException;
use PHPUnit\Framework\TestCase;

final class TorrentInfoHashResolverTest extends TestCase
{
    private TorrentInfoHashResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TorrentInfoHashResolver();
    }

    public function testFromMagnetNormalizesHexHashToLowercase(): void
    {
        $hash = str_repeat('AB', 20);

        $result = $this->resolver->fromMagnet('magnet:?xt=urn:btih:'.$hash.'&dn=Test');

        $this->assertSame(strtolower($hash), $result);
    }

    public function testFromMagnetDecodesBase32Hash(): void
    {
        // 32-char base32 encoding of the 20-byte hex hash below (BEP 9's alternate form).
        $hexHash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $base32Hash = 'VKVKVKVKVKVKVKVKVKVKVKVKVKVKVKVK';

        $result = $this->resolver->fromMagnet('magnet:?xt=urn:btih:'.$base32Hash);

        $this->assertSame($hexHash, $result);
    }

    public function testFromMagnetThrowsWhenNoBtihPresent(): void
    {
        $this->expectException(InvalidTorrentFileException::class);

        $this->resolver->fromMagnet('magnet:?dn=Test');
    }

    public function testFromTorrentFileContentHashesOnlyTheInfoDictBytes(): void
    {
        $infoBytes = $this->bencodeDict([
            'length' => $this->bencodeInt(100),
            'name' => $this->bencodeString('Test.Release.mkv'),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);

        $torrentBytes = $this->bencodeDict([
            // "announce" sorts before "info", exercising the string-value skip path before
            // the resolver ever reaches the key it actually wants.
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $infoBytes,
        ]);

        $expected = bin2hex(sha1($infoBytes, true));

        $this->assertSame($expected, $this->resolver->fromTorrentFileContent($torrentBytes));
    }

    public function testFromTorrentFileContentSkipsNestedListsAndDictsBeforeInfo(): void
    {
        $infoBytes = $this->bencodeDict(['length' => $this->bencodeInt(1)]);

        $torrentBytes = $this->bencodeDict([
            'announce-list' => 'll'.$this->bencodeString('http://a').$this->bencodeString('http://b').'ee',
            'creation date' => $this->bencodeInt(1_700_000_000),
            'info' => $infoBytes,
        ]);

        $expected = bin2hex(sha1($infoBytes, true));

        $this->assertSame($expected, $this->resolver->fromTorrentFileContent($torrentBytes));
    }

    public function testFromTorrentFileContentThrowsWhenNotADictionary(): void
    {
        $this->expectException(InvalidTorrentFileException::class);

        $this->resolver->fromTorrentFileContent('not bencode');
    }

    public function testFromTorrentFileContentThrowsWhenInfoKeyIsMissing(): void
    {
        $this->expectException(InvalidTorrentFileException::class);

        $this->resolver->fromTorrentFileContent($this->bencodeDict(['announce' => $this->bencodeString('http://a')]));
    }

    public function testFromTorrentFileContentRejectsDeeplyNestedListsInsteadOfExhaustingTheStack(): void
    {
        $this->expectException(InvalidTorrentFileException::class);

        // A tiny adversarial payload: thousands of nested "l"/"e" pairs before any "info" key is
        // ever reached — the DoS shape this limit exists to reject (see MAX_NESTING_DEPTH).
        $deeplyNested = str_repeat('l', 10_000).str_repeat('e', 10_000);
        $torrentBytes = $this->bencodeDict(['announce' => $deeplyNested, 'info' => $this->bencodeDict([])]);

        $this->resolver->fromTorrentFileContent($torrentBytes);
    }

    private function bencodeString(string $value): string
    {
        return \strlen($value).':'.$value;
    }

    private function bencodeInt(int $value): string
    {
        return 'i'.$value.'e';
    }

    /**
     * @param array<string, string> $entries pre-bencoded values, keyed by their (already sorted) key
     */
    private function bencodeDict(array $entries): string
    {
        $body = '';
        foreach ($entries as $key => $value) {
            $body .= $this->bencodeString($key).$value;
        }

        return 'd'.$body.'e';
    }
}
