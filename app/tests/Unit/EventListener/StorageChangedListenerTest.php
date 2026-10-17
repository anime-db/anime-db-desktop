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

use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\EventListener\StorageChangedListener;
use App\Service\Storage\StorageMarkerService;
use App\Service\WsPublisher;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Runs the listener inside a real flush (issue #1079): any write to a Storage row — whichever
 * service made it — must publish `storage.changed`, and other entities must stay silent.
 */
final class StorageChangedListenerTest extends TestCase
{
    private EntityManager $entityManager;

    /** @var list<array{string, mixed}> */
    private array $published = [];

    private string $dirA;
    private string $dirB;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $wsPublisher = $this->createStub(WsPublisher::class);
        $wsPublisher->method('publish')->willReturnCallback(function (string $event, mixed $data): void {
            $this->published[] = [$event, $data];
        });
        $this->entityManager->getEventManager()->addEventListener(
            [Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove],
            new StorageChangedListener($wsPublisher),
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

        $relocated = (new StorageMarkerService($this->entityManager))->relocateIfMarkerMoved($storage, $this->dirB);
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
}
