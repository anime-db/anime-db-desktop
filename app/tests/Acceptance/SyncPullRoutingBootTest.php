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

use App\Message\SyncPullMessage;
use App\Tests\Support\TemporaryDirectories;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Issue #870: a {@see SyncPullMessage} sent through the real bus of a cold-compiled container lands
 * on the `sync` transport (config/packages/messenger.yaml routing) — consumed by plugins-consumer,
 * not on `async`, which `PushSyncMessage` depends on.
 */
final class SyncPullRoutingBootTest extends KernelTestCase
{
    use TemporaryDirectories;

    /** @var array<string, string|null> */
    private array $originalEnv = [];
    private string $queueDatabasePath;

    protected function setUp(): void
    {
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-sync-pull-routing-queue-'.uniqid().'.sqlite';
        $databasePath = sys_get_temp_dir().'/anime-sync-pull-routing-db-'.uniqid().'.sqlite';
        $pluginsDir = $this->createTemporaryDirectory('anime-sync-pull-routing-plugins-');

        $values = [
            'APP_RUNTIME_DIR' => $this->createTemporaryDirectory('anime-sync-pull-routing-runtime-'),
            'PLUGINS_DIR' => $pluginsDir,
            'PLUGINS_CONFIG_PATH' => $pluginsDir.'/plugins.json',
            'DATABASE_URL' => 'sqlite:///'.$databasePath,
            'QUEUE_DATABASE_URL' => 'sqlite:///'.$this->queueDatabasePath,
            'CORE_VERSION' => '2.0.0',
        ];
        foreach ($values as $key => $value) {
            $this->originalEnv[$key] = $_SERVER[$key] ?? null;
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->queueDatabasePath)) {
            unlink($this->queueDatabasePath);
        }
        foreach ($this->originalEnv as $key => $original) {
            if ($original === null) {
                unset($_SERVER[$key], $_ENV[$key]);
            } else {
                $_SERVER[$key] = $original;
            }
        }

        $this->removeTemporaryDirectories();
    }

    public function testSyncPullMessageIsRoutedToTheSyncTransportOnly(): void
    {
        self::bootKernel(['debug' => false]);

        $syncTransport = $this->setUpTransport('messenger.transport.sync');
        $asyncTransport = $this->setUpTransport('messenger.transport.async');

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new SyncPullMessage('acme-source'));

        $envelopes = iterator_to_array($syncTransport->get());
        self::assertCount(1, $envelopes);
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(SyncPullMessage::class, $message);
        self::assertSame('acme-source', $message->pluginId);
        self::assertCount(0, iterator_to_array($asyncTransport->get()));
    }

    private function setUpTransport(string $serviceId): TransportInterface
    {
        $transport = self::getContainer()->get($serviceId);
        self::assertInstanceOf(SetupableTransportInterface::class, $transport);
        self::assertInstanceOf(TransportInterface::class, $transport);
        $transport->setup();

        return $transport;
    }
}
