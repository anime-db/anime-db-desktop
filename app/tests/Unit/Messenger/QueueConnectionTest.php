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

namespace App\Tests\Unit\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class QueueConnectionTest extends KernelTestCase
{
    public function testQueueConnectionUsesADedicatedSqliteFileFromDefaultConnection(): void
    {
        self::bootKernel();

        /** @var Connection $default */
        $default = self::getContainer()->get('doctrine.dbal.default_connection');
        /** @var Connection $queue */
        $queue = self::getContainer()->get('doctrine.dbal.queue_connection');

        $defaultPath = $default->getParams()['path'] ?? null;
        $queuePath = $queue->getParams()['path'] ?? null;

        $this->assertNotNull($defaultPath);
        $this->assertNotNull($queuePath);
        $this->assertNotSame($defaultPath, $queuePath);
        $this->assertStringEndsWith('queue.db', (string) $queuePath);
    }

    public function testAsyncTransportIsRegistered(): void
    {
        self::bootKernel();

        $transport = self::getContainer()->get('messenger.transport.async');

        $this->assertInstanceOf(TransportInterface::class, $transport);
    }
}
