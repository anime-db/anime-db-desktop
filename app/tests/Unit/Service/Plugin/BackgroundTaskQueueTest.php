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

use AnimeDb\PluginContracts\Background\BackgroundTask;
use App\Entity\ValueObject\PluginId;
use App\Message\RunPluginBackgroundTaskMessage;
use App\Service\Plugin\BackgroundTaskQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BackgroundTaskQueueTest extends TestCase
{
    public function testSubmitDispatchesTheTaskWrappedWithItsOwnScopedPluginId(): void
    {
        $task = new BackgroundTask('rescan');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (RunPluginBackgroundTaskMessage $message) use ($task): bool {
                return $message->pluginId === 'fake-vendor' && $message->task === $task;
            }))
            ->willReturn(new Envelope(new RunPluginBackgroundTaskMessage('fake-vendor', $task)));

        $queue = new BackgroundTaskQueue(new PluginId('fake-vendor'), $bus);
        $queue->submit($task);
    }

    /**
     * `submit(BackgroundTask $task)` has no parameter to name a plugin id at all — the id it
     * dispatches with comes only from the constructor-bound {@see PluginId} this instance was
     * scoped to. Two queue instances scoped to two different plugins, given the exact same task,
     * must each stamp their own dispatched message with their own id: nothing about the call a
     * plugin makes can override which plugin the queue reports it came from.
     */
    public function testTwoInstancesScopedToDifferentPluginsStampTheirOwnId(): void
    {
        $task = new BackgroundTask('rescan');

        $firstBus = $this->createMock(MessageBusInterface::class);
        $firstBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (RunPluginBackgroundTaskMessage $message): bool => $message->pluginId === 'fake-vendor'))
            ->willReturn(new Envelope(new RunPluginBackgroundTaskMessage('fake-vendor', $task)));

        $secondBus = $this->createMock(MessageBusInterface::class);
        $secondBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (RunPluginBackgroundTaskMessage $message): bool => $message->pluginId === 'fake-vendor-two'))
            ->willReturn(new Envelope(new RunPluginBackgroundTaskMessage('fake-vendor-two', $task)));

        (new BackgroundTaskQueue(new PluginId('fake-vendor'), $firstBus))->submit($task);
        (new BackgroundTaskQueue(new PluginId('fake-vendor-two'), $secondBus))->submit($task);
    }
}
