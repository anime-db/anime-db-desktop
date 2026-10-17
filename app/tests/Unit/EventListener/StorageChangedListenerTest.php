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

use App\Doctrine\DomainEventConnection;
use App\Doctrine\DomainEventEntityManager;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\DomainEvent\DomainEventPublisher;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\EventListener\StorageChangedListener;
use App\EventSubscriber\StorageChangedWsSubscriber;
use App\Service\Storage\StorageMarkerService;
use App\Service\WsPublisher;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Runs the listener and the WebSocket subscriber inside a real flush (issue #1079): any write to a
 * Storage row — whichever service made it — must publish `storage.changed`, but only once the
 * change is committed; other entities must stay silent.
 */
final class StorageChangedListenerTest extends TestCase
{
    private DomainEventConnection $connection;
    private EntityManager $rawEntityManager;
    private DomainEventEntityManager $entityManager;

    /** @var list<array{string, mixed}> */
    private array $published = [];
    /** @var list<string> */
    private array $log = [];

    private string $dirA;
    private string $dirB;

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
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => DomainEventConnection::class,
        ], $config);

        $dispatcher = new EventDispatcher();
        $publisher = new DomainEventPublisher($dispatcher, new NullLogger());
        $this->connection->setDomainEventPublisher($publisher);

        $this->rawEntityManager = new EntityManager($this->connection, $config);
        $this->entityManager = new DomainEventEntityManager($this->rawEntityManager, $publisher);
        (new SchemaTool($this->rawEntityManager))->createSchema($this->rawEntityManager->getMetadataFactory()->getAllMetadata());

        $wsPublisher = $this->createStub(WsPublisher::class);
        $wsPublisher->method('publish')->willReturnCallback(function (string $event, mixed $data): void {
            $this->published[] = [$event, $data];
            $this->log[] = 'published(tx='.($this->connection->isTransactionActive() ? 'open' : 'none').')';
        });
        $dispatcher->addSubscriber(new StorageChangedWsSubscriber($wsPublisher));
        $this->rawEntityManager->getEventManager()->addEventListener(
            [Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove],
            new StorageChangedListener($publisher),
        );

        $this->dirA = sys_get_temp_dir().'/storage-changed-a-'.uniqid();
        $this->dirB = sys_get_temp_dir().'/storage-changed-b-'.uniqid();
        mkdir($this->dirA);
        mkdir($this->dirB);
    }

    protected function tearDown(): void
    {
        @unlink($this->dirA.'/desktop.ini');
        @unlink($this->dirB.'/desktop.ini');
        @rmdir($this->dirA);
        @rmdir($this->dirB);
    }

    private function persistStorage(string $path): Storage
    {
        $storage = new Storage('Main folder', $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $this->published = [];
        $this->log = [];

        return $storage;
    }

    public function testCreationPublishesStorageChanged(): void
    {
        $storage = new Storage('Main folder', $this->dirA, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $this->assertSame([['storage.changed', ['id' => $storage->id]]], $this->published);
    }

    public function testPathChangePublishesStorageChanged(): void
    {
        $storage = $this->persistStorage($this->dirA);

        $storage->relocate($this->dirB);
        $this->entityManager->flush();

        $this->assertSame([['storage.changed', ['id' => $storage->id]]], $this->published);
    }

    public function testRelocationFoundByMarkerPublishesStorageChanged(): void
    {
        $storage = $this->persistStorage($this->dirA);
        file_put_contents($this->dirB.'/desktop.ini', \sprintf("[AnimeDB]\nid=%d\n", $storage->id));

        $relocated = (new StorageMarkerService($this->rawEntityManager))->relocateIfMarkerMoved($storage, $this->dirB);
        $this->entityManager->flush();

        $this->assertTrue($relocated);
        $this->assertSame([['storage.changed', ['id' => $storage->id]]], $this->published);
    }

    public function testRemovalPublishesStorageChangedWithTheOriginalId(): void
    {
        $storage = $this->persistStorage($this->dirA);
        $id = $storage->id;

        $this->entityManager->remove($storage);
        $this->entityManager->flush();

        $this->assertSame([['storage.changed', ['id' => $id]]], $this->published);
    }

    public function testFlushWithoutChangesPublishesNothing(): void
    {
        $this->persistStorage($this->dirA);

        $this->entityManager->flush();

        $this->assertSame([], $this->published);
    }

    public function testPublicationHappensAfterFlushReturnedNotInsideIt(): void
    {
        $this->entityManager->persist(new Storage('Main folder', $this->dirA, StorageType::Folder));
        $this->entityManager->flush();
        $this->log[] = 'flush returned';

        $this->assertSame(['published(tx=none)', 'flush returned'], $this->log);
    }

    public function testChangeInsideARolledBackTransactionPublishesNothing(): void
    {
        try {
            $this->entityManager->wrapInTransaction(function (): void {
                $this->entityManager->persist(new Storage('Main folder', $this->dirA, StorageType::Folder));
                $this->entityManager->flush();

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // Expected: the rollback must leave nothing behind.
        }

        $this->assertSame([], $this->published);
    }

    public function testChangeInsideAnOuterTransactionPublishesAfterTheOuterCommit(): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->persist(new Storage('Main folder', $this->dirA, StorageType::Folder));
            $this->entityManager->flush();
            $this->log[] = 'after inner flush';
        });
        $this->log[] = 'after wrapInTransaction';

        $this->assertSame(['after inner flush', 'published(tx=none)', 'after wrapInTransaction'], $this->log);
        $this->assertCount(1, $this->published);
    }
}
