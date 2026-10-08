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

namespace App\Tests\Unit\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncSeedDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncSeedDispatcherTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-sync-seed-dispatcher-test-'.uniqid().'.json';
        file_put_contents($this->path, (string) json_encode(['acme-a' => ['enabled' => true]]));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testFirstCallDispatchesTheSeedAndMarksThePluginSeeded(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $message): bool => $message instanceof SyncSeedMessage && $message->pluginId === 'acme-a'))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $store = new PluginsConfigStore($this->path);

        $this->assertTrue((new SyncSeedDispatcher($store, $bus, new NullLogger()))->dispatchIfNotSeeded(new PluginId('acme-a')));
        $this->assertTrue($store->getPluginSettings(new PluginId('acme-a'))['syncSeeded']);
    }

    public function testRepeatedCallDoesNotDispatchAgain(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $dispatcher = new SyncSeedDispatcher(new PluginsConfigStore($this->path), $bus, new NullLogger());

        $this->assertTrue($dispatcher->dispatchIfNotSeeded(new PluginId('acme-a')));
        $this->assertFalse($dispatcher->dispatchIfNotSeeded(new PluginId('acme-a')));
    }

    public function testResetFlagLetsALaterCallRetry(): void
    {
        file_put_contents($this->path, (string) json_encode(['acme-a' => ['enabled' => true, 'syncSeeded' => false]]));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->assertTrue((new SyncSeedDispatcher(new PluginsConfigStore($this->path), $bus, new NullLogger()))->dispatchIfNotSeeded(new PluginId('acme-a')));
    }

    public function testExhaustedLockSkipsTheDispatchWithoutThrowing(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $store = new PluginsConfigStore($this->path);

        // A second handle on the lock file starves PluginsConfigStore::acquireLock() in the same
        // process (flock() locks belong to the open file description).
        $lockHandle = fopen($this->path.'.lock', 'c');
        \assert($lockHandle !== false);
        flock($lockHandle, \LOCK_EX);

        try {
            $dispatched = (new SyncSeedDispatcher($store, $bus, new NullLogger()))->dispatchIfNotSeeded(new PluginId('acme-a'));
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
            @unlink($this->path.'.lock');
        }

        $this->assertFalse($dispatched);
        $this->assertArrayNotHasKey('syncSeeded', $store->getPluginSettings(new PluginId('acme-a')));
    }
}
