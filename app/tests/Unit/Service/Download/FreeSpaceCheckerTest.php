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

namespace App\Tests\Unit\Service\Download;

use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\FreeSpaceProvider;
use App\Service\Exception\InsufficientDiskSpaceException;
use App\Service\Exception\InvalidTorrentFileException;
use PHPUnit\Framework\TestCase;

final class FreeSpaceCheckerTest extends TestCase
{
    private const string ROOT = 'C:\\Users\\bob\\Downloads';
    private const int MIN_OVERHEAD_BYTES = 256 * 1024 * 1024;

    private function makeChecker(?int $freeBytes): FreeSpaceChecker
    {
        $provider = $this->createStub(FreeSpaceProvider::class);
        $provider->method('getFreeBytes')->willReturn($freeBytes);

        return new FreeSpaceChecker($provider);
    }

    public function testHasEnoughFreeSpaceIsTrueWhenFreeSpaceCoversSizePlusRatioOverhead(): void
    {
        // totalSize large enough that 2% (400_000_000) exceeds the 256 MB floor: margin = 2% of totalSize.
        $totalSize = 20_000_000_000;
        $margin = 400_000_000;
        $checker = $this->makeChecker($totalSize + $margin);

        $this->assertTrue($checker->hasEnoughFreeSpace($totalSize, self::ROOT));
    }

    public function testHasEnoughFreeSpaceIsFalseWhenOneByteShortOfSizePlusRatioOverhead(): void
    {
        $totalSize = 20_000_000_000;
        $margin = 400_000_000;
        $checker = $this->makeChecker($totalSize + $margin - 1);

        $this->assertFalse($checker->hasEnoughFreeSpace($totalSize, self::ROOT));
    }

    public function testHasEnoughFreeSpaceUsesTheMinimumOverheadFloorForASmallTorrent(): void
    {
        // 2% of 1000 bytes is negligible — the 256 MB floor dominates the margin.
        $totalSize = 1_000;
        $checker = $this->makeChecker($totalSize + self::MIN_OVERHEAD_BYTES);

        $this->assertTrue($checker->hasEnoughFreeSpace($totalSize, self::ROOT));
        $this->assertFalse($this->makeChecker($totalSize + self::MIN_OVERHEAD_BYTES - 1)->hasEnoughFreeSpace($totalSize, self::ROOT));
    }

    public function testHasEnoughFreeSpaceFailsOpenWhenFreeSpaceCannotBeDetermined(): void
    {
        $checker = $this->makeChecker(null);

        $this->assertTrue($checker->hasEnoughFreeSpace(1_000_000_000_000, self::ROOT));
    }

    public function testAssertEnoughSpaceForTorrentFilePassesForASingleFileTorrentThatFits(): void
    {
        $checker = $this->makeChecker(10_000_000_000);

        $checker->assertEnoughSpaceForTorrentFile($this->singleFileTorrent(1_000_000_000), self::ROOT);

        $this->addToAssertionCount(1);
    }

    public function testAssertEnoughSpaceForTorrentFileThrowsForASingleFileTorrentThatDoesNotFit(): void
    {
        $checker = $this->makeChecker(100_000_000);

        $this->expectException(InsufficientDiskSpaceException::class);

        $checker->assertEnoughSpaceForTorrentFile($this->singleFileTorrent(1_000_000_000), self::ROOT);
    }

    public function testAssertEnoughSpaceForTorrentFileSumsAllFilesInAMultiFileTorrent(): void
    {
        // Free space would fit one 500 MB file's size (+ overhead) but not both files summed —
        // this only fails if "info.files[].length" is actually being summed, not just read once.
        $checker = $this->makeChecker(500_000_000 + self::MIN_OVERHEAD_BYTES);

        $this->expectException(InsufficientDiskSpaceException::class);

        $checker->assertEnoughSpaceForTorrentFile($this->multiFileTorrent([500_000_000, 500_000_000]), self::ROOT);
    }

    public function testAssertEnoughSpaceForTorrentFilePassesForAMultiFileTorrentThatFits(): void
    {
        $checker = $this->makeChecker(2_000_000_000);

        $checker->assertEnoughSpaceForTorrentFile($this->multiFileTorrent([500_000_000, 500_000_000]), self::ROOT);

        $this->addToAssertionCount(1);
    }

    public function testAssertEnoughSpaceForTorrentFileThrowsInvalidTorrentFileExceptionWhenInfoDictIsMissing(): void
    {
        $checker = $this->makeChecker(10_000_000_000);

        $this->expectException(InvalidTorrentFileException::class);

        $checker->assertEnoughSpaceForTorrentFile($this->bencodeDict(['announce' => $this->bencodeString('http://tracker.local')]), self::ROOT);
    }

    public function testAssertEnoughSpaceForTorrentFileRejectsANegativeLength(): void
    {
        $checker = $this->makeChecker(1);

        $this->expectException(InvalidTorrentFileException::class);

        $checker->assertEnoughSpaceForTorrentFile($this->singleFileTorrent(-1), self::ROOT);
    }

    public function testAssertEnoughSpaceForTorrentFileRejectsAnOverflowingTotalSize(): void
    {
        $checker = $this->makeChecker(1);

        $this->expectException(InvalidTorrentFileException::class);

        $checker->assertEnoughSpaceForTorrentFile($this->multiFileTorrent([\PHP_INT_MAX, \PHP_INT_MAX]), self::ROOT);
    }

    /**
     * Issue #844: PCRE's `$` anchor (without the `D` modifier) matches just before a final `\n`,
     * so `/^-?\d+$/` alone would accept "1000\n" as a valid bencoded integer and silently read it
     * as 1000 via `(int)` cast. A bencode token spells its own end ("i1000\ne"), so this is
     * directly craftable in a `.torrent` file's bytes — no secondary filesystem or type guard
     * stands in the way the way it does for IconExtension.
     */
    public function testAssertEnoughSpaceForTorrentFileRejectsALengthWithTrailingNewline(): void
    {
        $checker = $this->makeChecker(10_000_000_000);

        $infoBytes = $this->bencodeDict([
            'length' => "i1000\ne",
            'name' => $this->bencodeString('Test.Release.mkv'),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);
        $torrent = $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $infoBytes,
        ]);

        $this->expectException(InvalidTorrentFileException::class);

        $checker->assertEnoughSpaceForTorrentFile($torrent, self::ROOT);
    }

    private function singleFileTorrent(int $length): string
    {
        $infoBytes = $this->bencodeDict([
            'length' => $this->bencodeInt($length),
            'name' => $this->bencodeString('Test.Release.mkv'),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);

        return $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $infoBytes,
        ]);
    }

    /** @param list<int> $fileLengths */
    private function multiFileTorrent(array $fileLengths): string
    {
        $files = array_map(
            fn (int $length): string => $this->bencodeDict([
                'length' => $this->bencodeInt($length),
                'path' => 'l'.$this->bencodeString('part'.$length.'.mkv').'e',
            ]),
            $fileLengths,
        );

        $infoBytes = $this->bencodeDict([
            'files' => 'l'.implode('', $files).'e',
            'name' => $this->bencodeString('Season.Pack'),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);

        return $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $infoBytes,
        ]);
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
