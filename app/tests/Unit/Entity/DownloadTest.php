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

use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidInfoHashException;
use App\Entity\TvAnime;
use PHPUnit\Framework\TestCase;

final class DownloadTest extends TestCase
{
    private const string INFO_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function makeAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);

        return $anime;
    }

    public function testConstructorSetsPendingStatus(): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());

        $this->assertSame(DownloadStatus::Pending, $download->getStatus());
        $this->assertFalse($download->isCompleted());
        $this->assertSame(self::INFO_HASH, $download->getInfoHash());
    }

    public function testConstructorRejectsUppercaseInfoHash(): void
    {
        $this->expectException(InvalidInfoHashException::class);

        new Download(strtoupper(self::INFO_HASH), $this->makeAnime());
    }

    public function testConstructorRejectsShortInfoHash(): void
    {
        $this->expectException(InvalidInfoHashException::class);

        new Download('abc', $this->makeAnime());
    }

    public function testConstructorRejectsInfoHashWithTrailingNewline(): void
    {
        $this->expectException(InvalidInfoHashException::class);

        new Download(self::INFO_HASH."\n", $this->makeAnime());
    }

    public function testMarkCompletedTransitionsOnceAndReportsSecondCallAsNoOp(): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());

        $this->assertTrue($download->markCompleted());
        $this->assertTrue($download->isCompleted());
        $this->assertFalse($download->markCompleted());
    }

    public function testMarkFailedTransitionsOnceAndReportsSecondCallAsNoOp(): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());

        $this->assertTrue($download->markFailed());
        $this->assertTrue($download->isFailed());
        $this->assertSame(DownloadStatus::Failed, $download->getStatus());
        $this->assertFalse($download->markFailed());
    }

    public function testMarkFailedIsANoOpOnceAlreadyCompleted(): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());
        $download->markCompleted();

        $this->assertFalse($download->markFailed());
        $this->assertTrue($download->isCompleted());
        $this->assertFalse($download->isFailed());
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function retryableFailureReasonProvider(): iterable
    {
        yield 'disk_space' => ['disk_space'];
        yield 'name_conflict' => ['name_conflict'];
        yield 'move_failed' => ['move_failed'];
        yield 'null (pre-#852 row)' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryableFailureReasonProvider')]
    public function testRetryTransitionsFailedToPendingAndResetsReasonAndMoveAttemptsForRetryableReasons(?string $reason): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());
        $download->markFailed($reason);
        $download->incrementMoveAttempts();
        $download->incrementMoveAttempts();

        $this->assertTrue($download->retry());

        $this->assertSame(DownloadStatus::Pending, $download->getStatus());
        $this->assertNull($download->getFailureReason());
        $this->assertSame(0, $download->getMoveAttempts());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonRetryableFailureReasonProvider(): iterable
    {
        // storage_conflict: the torrent is already fully downloaded; retrying cannot free the
        // folder another anime's pointer already occupies.
        yield 'storage_conflict' => ['storage_conflict'];
        // legacy_layout: the row has no target storage to retry into at all.
        yield 'legacy_layout' => ['legacy_layout'];
        yield 'an unrecognized reason' => ['unexpected_layout'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonRetryableFailureReasonProvider')]
    public function testRetryRefusesForNonRetryableFailureReasonsAndChangesNothing(string $reason): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());
        $download->markFailed($reason);

        $this->assertFalse($download->retry());

        $this->assertSame(DownloadStatus::Failed, $download->getStatus());
        $this->assertSame($reason, $download->getFailureReason());
    }

    public function testRetryRefusesForPendingStatus(): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());

        $this->assertFalse($download->retry());
        $this->assertSame(DownloadStatus::Pending, $download->getStatus());
    }

    public function testRetryRefusesForCompletedStatus(): void
    {
        $download = new Download(self::INFO_HASH, $this->makeAnime());
        $download->markCompleted();

        $this->assertFalse($download->retry());
        $this->assertTrue($download->isCompleted());
    }

    public function testIsRetryableFailureReasonMatchesRetryItself(): void
    {
        $this->assertTrue(Download::isRetryableFailureReason(null));
        $this->assertTrue(Download::isRetryableFailureReason('disk_space'));
        $this->assertFalse(Download::isRetryableFailureReason('storage_conflict'));
        $this->assertFalse(Download::isRetryableFailureReason('legacy_layout'));
    }
}
