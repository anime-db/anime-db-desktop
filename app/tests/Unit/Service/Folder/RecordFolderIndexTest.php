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

namespace App\Tests\Unit\Service\Folder;

use AnimeDb\PluginContracts\Model\AnimeId;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Service\Folder\RecordFolderIndex;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class RecordFolderIndexTest extends TestCase
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

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testOnlyRecordsWithAFolderOnAWritableStorageAreReturnedInIdOrder(): void
    {
        $folder = $this->storage(StorageType::Folder, '/definitely/not/mounted');
        $external = $this->storage(StorageType::External, '/mnt/usb');
        $disc = $this->storage(StorageType::ExternalR, '/mnt/disc');
        $video = $this->storage(StorageType::Video, null);

        $noStorage = $this->record(null, null);
        $emptyPath = $this->record($folder, '');
        $blankPath = $this->record($folder, '  ');
        $nullPath = $this->record($folder, null);
        $onDisc = $this->record($disc, 'Disc record');
        $onVideo = $this->record($video, 'Tape');
        $first = $this->record($folder, 'One');
        $second = $this->record($external, 'Two');
        $third = $this->record($folder, 'Three');

        $ids = $this->ids((new RecordFolderIndex($this->entityManager))->recordsWithFolder(null, 100));

        self::assertSame([$first, $second, $third], $ids);
        self::assertNotContains($noStorage, $ids);
        self::assertNotContains($emptyPath, $ids);
        self::assertNotContains($blankPath, $ids);
        self::assertNotContains($nullPath, $ids);
        self::assertNotContains($onDisc, $ids);
        self::assertNotContains($onVideo, $ids);
    }

    public function testCursorIsExclusiveAndLimitIsHonoured(): void
    {
        $folder = $this->storage(StorageType::Folder, '/mnt/lib');
        $ids = [];
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $ids[] = $this->record($folder, $name);
        }
        $index = new RecordFolderIndex($this->entityManager);

        self::assertSame([$ids[0], $ids[1]], $this->ids($index->recordsWithFolder(null, 2)));
        self::assertSame([$ids[2], $ids[3]], $this->ids($index->recordsWithFolder(new AnimeId($ids[1]), 2)));
        self::assertSame([$ids[3]], $this->ids($index->recordsWithFolder(new AnimeId($ids[2]), 1)));
        self::assertSame([], $index->recordsWithFolder(new AnimeId($ids[3]), 5));
    }

    public function testLimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecordFolderIndex($this->entityManager))->recordsWithFolder(null, 0);
    }

    private function storage(StorageType $type, ?string $path): Storage
    {
        $storage = new Storage('Storage '.$type->value, $path, $type);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    private function record(?Storage $storage, ?string $path): int
    {
        $anime = new MovieAnime();
        $anime->setTitle('Record')->setWatchStatus(WatchStatus::Watching);
        $anime->setStorage($storage)->setStoragePath($path);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return (int) $anime->id;
    }

    /**
     * @param list<AnimeId> $ids
     *
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_map(static fn (AnimeId $id): int => $id->value, $ids);
    }
}
