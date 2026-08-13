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

namespace App\Tests\Unit\EventListener;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Event\WatchProgressChangedManuallyEvent;
use App\EventListener\DomainEventListener;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Exercises the infrastructure half of the domain-event mechanism (issue #371): given an Anime
 * whose domain method already recorded events (see AnimeTest/SeriesAnimeTest for that half),
 * does the listener release and dispatch them, and does it stay quiet for both an Anime with no
 * pending events and other entity types.
 */
final class DomainEventListenerTest extends TestCase
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

    public function testPostPersistReleasesAndDispatchesRecordedEvents(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->changeWatchStatusManually(WatchStatus::Plan);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(WatchProgressChangedManuallyEvent::class));

        $listener = new DomainEventListener($eventDispatcher);
        $listener->postPersist(new PostPersistEventArgs($anime, $this->entityManager));

        // The listener must drain the entity's recorded events, not just peek at them —
        // otherwise a later postUpdate would re-dispatch the same event a second time.
        $this->assertSame([], $anime->releaseEvents());
    }

    public function testPostUpdateReleasesAndDispatchesRecordedEvents(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $anime->releaseEvents();

        $anime->changeWatchStatusManually(WatchStatus::Watching);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(WatchProgressChangedManuallyEvent::class));

        $listener = new DomainEventListener($eventDispatcher);
        $listener->postUpdate(new PostUpdateEventArgs($anime, $this->entityManager));
    }

    public function testDoesNothingWhenNoEventsAreRecorded(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $listener = new DomainEventListener($eventDispatcher);
        $listener->postPersist(new PostPersistEventArgs($anime, $this->entityManager));
    }

    public function testDoesNothingForOtherEntityTypes(): void
    {
        $storage = new Storage('Main folder', 'C:\\Anime', StorageType::Folder);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $listener = new DomainEventListener($eventDispatcher);
        $listener->postPersist(new PostPersistEventArgs($storage, $this->entityManager));
    }
}
