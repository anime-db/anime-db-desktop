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

use App\Event\StorageConnected;
use App\EventSubscriber\StorageConnectedScanSubscriber;
use App\Message\ScanStorageMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StorageConnectedScanSubscriberTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/storage-connected-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        (new FilesystemAdapter('test', 0, $this->cacheDir))->clear();
        @rmdir($this->cacheDir.'/test');
        @rmdir($this->cacheDir);
    }

    /** @param list<object> $dispatched */
    private function createSubscriber(array &$dispatched): StorageConnectedScanSubscriber
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        // A fresh adapter instance over the same directory stands for a separate OS process.
        return new StorageConnectedScanSubscriber($bus, new FilesystemAdapter('test', 0, $this->cacheDir));
    }

    public function testQueuesScanOfTheConnectedStorage(): void
    {
        $dispatched = [];
        $this->createSubscriber($dispatched)->onStorageConnected(new StorageConnected(3));

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(ScanStorageMessage::class, $dispatched[0]);
        $this->assertSame(3, $dispatched[0]->storageId);
    }

    public function testRepeatedEventForTheSameStorageFromAnotherProcessQueuesNoSecondScan(): void
    {
        $dispatched = [];
        $this->createSubscriber($dispatched)->onStorageConnected(new StorageConnected(3));
        $this->createSubscriber($dispatched)->onStorageConnected(new StorageConnected(3));

        $this->assertCount(1, $dispatched);
    }

    public function testOtherStorageIsNotLimitedByTheFirstOne(): void
    {
        $dispatched = [];
        $this->createSubscriber($dispatched)->onStorageConnected(new StorageConnected(3));
        $this->createSubscriber($dispatched)->onStorageConnected(new StorageConnected(4));

        $this->assertCount(2, $dispatched);
        $this->assertEquals(new ScanStorageMessage(4), $dispatched[1]);
    }

    public function testLimitIsKeptInTheCacheForTenMinutesUnderAPsr6SafeKeyPerStorage(): void
    {
        $dispatched = [];
        $this->createSubscriber($dispatched)->onStorageConnected(new StorageConnected(3));

        $this->assertSame(600, StorageConnectedScanSubscriber::MIN_INTERVAL_SECONDS);
        $key = StorageConnectedScanSubscriber::cacheKey(3);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
        $this->assertNotSame($key, StorageConnectedScanSubscriber::cacheKey(4));
        $this->assertTrue((new FilesystemAdapter('test', 0, $this->cacheDir))->getItem($key)->isHit());
    }
}
