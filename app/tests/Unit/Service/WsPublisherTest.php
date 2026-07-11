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

namespace App\Tests\Unit\Service;

use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class WsPublisherTest extends TestCase
{
    private Connection $connection;
    private WsPublisher $publisher;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->publisher = new WsPublisher($this->connection);
    }

    public function testSinceReturnsEmptyArrayWhenQueueIsEmpty(): void
    {
        $this->assertSame([], $this->publisher->since(0));
    }

    public function testPublishAndSinceCycle(): void
    {
        $this->publisher->publish('test.event', ['key' => 'value']);
        $events = $this->publisher->since(0);

        $this->assertCount(1, $events);
        $this->assertSame('test.event', $events[0]['event']);
        $this->assertSame(['key' => 'value'], $events[0]['data']);
    }

    public function testSinceDoesNotRemoveEventsFromQueue(): void
    {
        $this->publisher->publish('event.one', null);
        $this->publisher->publish('event.two', null);

        $events = $this->publisher->since(0);

        $this->assertCount(2, $events);
        $this->assertSame('event.one', $events[0]['event']);
        $this->assertSame('event.two', $events[1]['event']);

        // Reading again with the same cursor sees the same events — since() never deletes.
        $this->assertSame($events, $this->publisher->since(0));
    }

    public function testSinceReturnsOnlyEventsAfterGivenCursor(): void
    {
        $this->publisher->publish('first', 1);
        $this->publisher->publish('second', 2);
        $this->publisher->publish('third', 3);

        $all = $this->publisher->since(0);
        $this->assertCount(3, $all);

        $remaining = $this->publisher->since($all[0]['id']);

        $this->assertCount(2, $remaining);
        $this->assertSame('second', $remaining[0]['event']);
        $this->assertSame('third', $remaining[1]['event']);
    }

    public function testPublishAcceptsNullData(): void
    {
        $this->publisher->publish('null.event', null);
        $events = $this->publisher->since(0);

        $this->assertCount(1, $events);
        $this->assertNull($events[0]['data']);
    }

    /**
     * Regression guard for issue #146: the previous next() deleted the row it returned, so of
     * two concurrent WebSocket connections polling the same queue, only one would ever observe
     * a given event. Each connection now tracks its own cursor via since(), so both observe the
     * same publish independently.
     */
    public function testTwoConsumersBothSeeTheSamePublishedEvent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ws_events_');
        $this->assertIsString($path);

        try {
            $publisherConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $firstConsumerConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $secondConsumerConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);

            $publisher = new WsPublisher($publisherConnection);
            $firstConsumer = new WsPublisher($firstConsumerConnection);
            $secondConsumer = new WsPublisher($secondConsumerConnection);

            $publisher->publish('scan.progress', ['percent' => 42]);

            // Two independent connections, each with its own cursor — mirrors two concurrent
            // /ws connections (e.g. native/ws-client.js and the storage scan page, issue #146).
            $firstConsumerEvents = $firstConsumer->since(0);
            $secondConsumerEvents = $secondConsumer->since(0);

            $this->assertCount(1, $firstConsumerEvents);
            $this->assertCount(1, $secondConsumerEvents);
            $this->assertSame('scan.progress', $firstConsumerEvents[0]['event']);
            $this->assertSame('scan.progress', $secondConsumerEvents[0]['event']);
            $this->assertSame(['percent' => 42], $firstConsumerEvents[0]['data']);
            $this->assertSame(['percent' => 42], $secondConsumerEvents[0]['data']);
        } finally {
            unlink($path);
        }
    }

    public function testPublishPrunesEventsOlderThanTtl(): void
    {
        $this->publisher->publish('warm.up', null); // ensures the table exists before we backdate a row
        $this->connection->update('ws_events', ['created_at' => '2000-01-01 00:00:00'], ['event' => 'warm.up']);

        $this->publisher->publish('recent.event', null);

        $events = $this->publisher->since(0);

        $this->assertCount(1, $events);
        $this->assertSame('recent.event', $events[0]['event']);
    }

    public function testInitialLastIdSkipsOldBacklogButKeepsRecentEvents(): void
    {
        $this->publisher->publish('old.event', null);
        $this->connection->update('ws_events', ['created_at' => '2000-01-01 00:00:00'], ['event' => 'old.event']);

        $this->publisher->publish('recent.event', null);

        $lastId = $this->publisher->initialLastId();
        $events = $this->publisher->since($lastId);

        $this->assertCount(1, $events);
        $this->assertSame('recent.event', $events[0]['event']);
    }

    /**
     * Regression guard for issue #94: the queue must live in the shared SQLite file, not in
     * per-connection memory, so an event published on one connection (e.g. the consumer
     * process, issue #97) is visible to a WsPublisher on a different connection to the same
     * file (e.g. the HTTP process serving WsController).
     */
    public function testEventPublishedOnOneConnectionIsVisibleOnAnother(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ws_events_');
        $this->assertIsString($path);

        try {
            $publisherConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            $consumerConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);

            $publisherSide = new WsPublisher($publisherConnection);
            $consumerSide = new WsPublisher($consumerConnection);

            $consumerSide->publish('scan.progress', ['percent' => 42]);
            $events = $publisherSide->since(0);

            $this->assertCount(1, $events);
            $this->assertSame('scan.progress', $events[0]['event']);
            $this->assertSame(['percent' => 42], $events[0]['data']);
        } finally {
            unlink($path);
        }
    }
}
