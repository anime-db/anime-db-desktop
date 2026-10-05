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

namespace App\Tests\Acceptance;

use App\Message\PushSyncMessage;
use App\Message\SyncSeedMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Routing of the connect-seed pull onto its own `sync` transport (config/packages/messenger.yaml),
 * so a long pull never occupies the `async` worker `PushSyncMessage` depends on. Reads each
 * transport's own `get()` — a single immediate query — so a misrouted message fails at once.
 */
final class SyncSeedTransportBootTest extends KernelTestCase
{
    private string $queueDatabasePath;
    private ?string $originalQueueDatabaseUrl;
    private ?string $originalQueueDatabaseUrlEnv;

    protected function setUp(): void
    {
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-sync-seed-transport-queue-'.uniqid().'.sqlite';
        $_SERVER['QUEUE_DATABASE_URL'] = $_ENV['QUEUE_DATABASE_URL'] = 'sqlite:///'.$this->queueDatabasePath;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->queueDatabasePath)) {
            unlink($this->queueDatabasePath);
        }
        $this->restore($_SERVER, $this->originalQueueDatabaseUrl);
        $this->restore($_ENV, $this->originalQueueDatabaseUrlEnv);
    }

    public function testSeedMessageRoutesToSyncTransportNotAsync(): void
    {
        self::bootKernel(['debug' => false]);

        $async = $this->setUpTransport('messenger.transport.async');
        $sync = $this->setUpTransport('messenger.transport.sync');

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new SyncSeedMessage('acme-sync'));

        $syncEnvelopes = iterator_to_array($sync->get());
        self::assertCount(1, $syncEnvelopes);
        self::assertInstanceOf(SyncSeedMessage::class, $syncEnvelopes[0]->getMessage());
        self::assertCount(0, iterator_to_array($async->get()));
    }

    public function testPushMessageStaysOnAsyncTransport(): void
    {
        self::bootKernel(['debug' => false]);

        $async = $this->setUpTransport('messenger.transport.async');
        $sync = $this->setUpTransport('messenger.transport.sync');

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new PushSyncMessage(1, new \DateTimeImmutable()));

        $asyncEnvelopes = iterator_to_array($async->get());
        self::assertCount(1, $asyncEnvelopes);
        self::assertInstanceOf(PushSyncMessage::class, $asyncEnvelopes[0]->getMessage());
        self::assertCount(0, iterator_to_array($sync->get()));
    }

    public function testSyncTransportRedeliverTimeoutIsOneHour(): void
    {
        self::bootKernel(['debug' => false]);

        $transport = self::getContainer()->get('messenger.transport.sync');
        self::assertInstanceOf(DoctrineTransport::class, $transport);

        $connection = (new \ReflectionProperty(DoctrineTransport::class, 'connection'))->getValue($transport);
        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame(3600, $connection->getConfiguration()['redeliver_timeout']);
        self::assertSame('sync', $connection->getConfiguration()['queue_name']);
    }

    private function setUpTransport(string $serviceId): TransportInterface
    {
        $transport = self::getContainer()->get($serviceId);
        self::assertInstanceOf(SetupableTransportInterface::class, $transport);
        self::assertInstanceOf(TransportInterface::class, $transport);
        $transport->setup();

        return $transport;
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function restore(array &$bag, ?string $original): void
    {
        if ($original === null) {
            unset($bag['QUEUE_DATABASE_URL']);
        } else {
            $bag['QUEUE_DATABASE_URL'] = $original;
        }
    }
}
