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

namespace App\Tests\Unit\EventSubscriber;

use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use AnimeDb\PluginContracts\Download\DownloadTaskId;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\EventSubscriber\DownloadCompletedNotificationSubscriber;
use App\Tests\Support\RecordingNotificationCenter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DownloadCompletedNotificationSubscriberTest extends TestCase
{
    private function event(): DownloadCompletedEvent
    {
        return new DownloadCompletedEvent(new AnimeId(7), new DownloadTaskId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    private function subscriber(RecordingNotificationCenter $center, ?Anime $anime, ?\Throwable $findFails = null): DownloadCompletedNotificationSubscriber
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        if ($findFails !== null) {
            $entityManager->method('find')->willThrowException($findFails);
        } else {
            $entityManager->method('find')->willReturn($anime);
        }

        return new DownloadCompletedNotificationSubscriber($center, $entityManager, new NullLogger());
    }

    public function testRecordsDownloadCompletedForTheAnime(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $center = new RecordingNotificationCenter();

        $this->subscriber($center, $anime)->onDownloadCompleted($this->event());

        $this->assertCount(1, $center->drafts);
        $draft = $center->drafts[0];
        $this->assertSame('download_completed', $draft->kind);
        $this->assertSame(['name' => 'Trigun'], $draft->params);
        $this->assertSame(['type' => 'anime', 'id' => '7'], $draft->subject());
    }

    public function testNeverThrowsWhenRecordingOrLookupFails(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);

        $this->subscriber(new RecordingNotificationCenter(failing: true), $anime)->onDownloadCompleted($this->event());
        $this->subscriber(new RecordingNotificationCenter(), null, new \RuntimeException('db is gone'))->onDownloadCompleted($this->event());

        $center = new RecordingNotificationCenter();
        $this->subscriber($center, null)->onDownloadCompleted($this->event());
        $this->assertSame([], $center->drafts);
    }
}
