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

namespace App\Tests\Unit\EventListener;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\EventListener\AnimeSearchIndexListener;
use App\Message\DeleteFromIndexMessage;
use App\Message\IndexAnimeMessage;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Exercises the Doctrine lifecycle wiring itself (issue #197): given real postPersist/
 * postUpdate/postRemove event args, does the listener dispatch the right message with the
 * right id, and does it stay quiet for entities that are not an Anime (the listener is a
 * plain #[AsDoctrineListener], so it also receives every other entity's lifecycle events).
 */
final class AnimeSearchIndexListenerTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testPostPersistDispatchesIndexAnimeMessage(): void
    {
        $anime = $this->persistAnime();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($this->requireId($anime))))
            ->willReturn(new Envelope(new IndexAnimeMessage($this->requireId($anime))));

        $listener = new AnimeSearchIndexListener($messageBus);
        $listener->postPersist(new PostPersistEventArgs($anime, $this->entityManager));
    }

    public function testPostUpdateDispatchesIndexAnimeMessage(): void
    {
        $anime = $this->persistAnime();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new IndexAnimeMessage($this->requireId($anime))))
            ->willReturn(new Envelope(new IndexAnimeMessage($this->requireId($anime))));

        $listener = new AnimeSearchIndexListener($messageBus);
        $listener->postUpdate(new PostUpdateEventArgs($anime, $this->entityManager));
    }

    public function testPostRemoveDispatchesDeleteFromIndexMessage(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new DeleteFromIndexMessage($animeId)))
            ->willReturn(new Envelope(new DeleteFromIndexMessage($animeId)));

        $listener = new AnimeSearchIndexListener($messageBus);
        $listener->postRemove(new PostRemoveEventArgs($anime, $this->entityManager));
    }

    public function testLifecycleEventsOfOtherEntitiesAreIgnored(): void
    {
        $storage = new Storage('Main folder', 'C:\\Anime', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $listener = new AnimeSearchIndexListener($messageBus);
        $listener->postPersist(new PostPersistEventArgs($storage, $this->entityManager));
        $listener->postUpdate(new PostUpdateEventArgs($storage, $this->entityManager));
        $listener->postRemove(new PostRemoveEventArgs($storage, $this->entityManager));
    }

    private function persistAnime(): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function requireId(MovieAnime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');
    }
}
