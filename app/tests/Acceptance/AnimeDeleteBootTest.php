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

use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Message\DeleteFromIndexMessage;
use App\Service\AnimeDeleteOutcome;
use App\Service\AnimeDeleteService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Deleting a catalog entry through the real container: every Doctrine listener is registered, so
 * the search-index listener sees the removal the way it does in production — where the ORM has
 * already nulled the entity id by the time postRemove fires.
 */
final class AnimeDeleteBootTest extends KernelTestCase
{
    private string $databasePath;
    private string $queueDatabasePath;
    /** @var array<string, array{0: ?string, 1: ?string}> */
    private array $originals = [];

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir().'/anime-delete-boot-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-delete-boot-queue-'.uniqid().'.sqlite';

        foreach (['DATABASE_URL' => $this->databasePath, 'QUEUE_DATABASE_URL' => $this->queueDatabasePath] as $name => $path) {
            $this->originals[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = 'sqlite:///'.$path;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->databasePath, $this->queueDatabasePath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->originals as $name => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }
    }

    public function testDeleteRemovesTheEntryAndQueuesExactlyOneIndexDeletion(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(SetupableTransportInterface::class, $transport);
        self::assertInstanceOf(TransportInterface::class, $transport);
        $transport->setup();

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Persisted anime has no id.');

        // Drop the IndexAnimeMessage queued by the persist above.
        foreach (iterator_to_array($transport->get()) as $envelope) {
            $transport->ack($envelope);
        }

        $service = $container->get(AnimeDeleteService::class);
        self::assertInstanceOf(AnimeDeleteService::class, $service);

        self::assertSame(AnimeDeleteOutcome::Deleted, $service->delete($anime));

        $entityManager->clear();
        self::assertNull($entityManager->find(Anime::class, $animeId));

        $messages = array_map(
            static fn ($envelope) => $envelope->getMessage(),
            iterator_to_array($transport->get()),
        );
        self::assertCount(1, $messages);
        self::assertInstanceOf(DeleteFromIndexMessage::class, $messages[0]);
        self::assertSame($animeId, $messages[0]->animeId);
    }
}
