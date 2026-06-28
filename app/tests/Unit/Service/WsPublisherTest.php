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
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('apcu')]
final class WsPublisherTest extends TestCase
{
    private WsPublisher $publisher;

    protected function setUp(): void
    {
        ini_set('apc.enable_cli', '1');
        apcu_clear_cache();
        $this->publisher = new WsPublisher();
    }

    protected function tearDown(): void
    {
        apcu_clear_cache();
    }

    public function testNextReturnsNullWhenQueueIsEmpty(): void
    {
        $this->assertNull($this->publisher->next());
    }

    public function testPublishAndNextCycle(): void
    {
        $this->publisher->publish('test.event', ['key' => 'value']);
        $event = $this->publisher->next();

        $this->assertNotNull($event);
        $this->assertSame('test.event', $event['event']);
        $this->assertSame(['key' => 'value'], $event['data']);
    }

    public function testNextRemovesEventFromQueue(): void
    {
        $this->publisher->publish('event.one', null);
        $this->publisher->publish('event.two', null);

        $first = $this->publisher->next();
        $second = $this->publisher->next();

        if (null === $first || null === $second) {
            $this->fail('Expected two events in queue');
        }

        $this->assertSame('event.one', $first['event']);
        $this->assertSame('event.two', $second['event']);
        $this->assertNull($this->publisher->next());
    }

    public function testQueueIsFirstInFirstOut(): void
    {
        $this->publisher->publish('first', 1);
        $this->publisher->publish('second', 2);
        $this->publisher->publish('third', 3);

        $first = $this->publisher->next();
        $second = $this->publisher->next();
        $third = $this->publisher->next();

        if (null === $first || null === $second || null === $third) {
            $this->fail('Expected three events in queue');
        }

        $this->assertSame('first', $first['event']);
        $this->assertSame('second', $second['event']);
        $this->assertSame('third', $third['event']);
    }

    public function testPublishAcceptsNullData(): void
    {
        $this->publisher->publish('null.event', null);
        $event = $this->publisher->next();

        $this->assertNotNull($event);
        $this->assertNull($event['data']);
    }
}
