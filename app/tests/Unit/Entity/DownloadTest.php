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
}
