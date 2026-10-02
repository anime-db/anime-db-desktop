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

namespace App\Tests\Unit\Service\Download;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Service\Download\DownloadFolderPointer;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class DownloadFolderPointerTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadFolderPointer $pointer;

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

        $this->pointer = new DownloadFolderPointer();
    }

    private function persistStorage(string $path): Storage
    {
        $storage = new Storage('Downloads', $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    private function persistAnime(?Storage $storage, ?string $storagePath): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan)->setStorage($storage)->setStoragePath($storagePath);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function persistCompletedDownload(TvAnime $anime, ?Storage $snapshotStorage, ?string $snapshotPath): Download
    {
        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        if ($snapshotStorage !== null && $snapshotPath !== null) {
            $download->recordLinkedStorage($snapshotStorage, $snapshotPath);
        }
        $this->entityManager->persist($download);
        $this->entityManager->flush();

        return $download;
    }

    public function testReleasesThePointerWhenItExactlyMatchesTheSnapshot(): void
    {
        $storage = $this->persistStorage('C:\\Users\\bob\\Downloads');
        $anime = $this->persistAnime($storage, 'some-release');
        $download = $this->persistCompletedDownload($anime, $storage, 'some-release');

        $released = $this->pointer->releaseIfOwnedBy($download);

        $this->assertTrue($released);
        $this->assertNull($anime->getStorage());
        $this->assertNull($anime->getStoragePath());
    }

    public function testKeepsThePointerWhenTheStorageDiffers(): void
    {
        $snapshotStorage = $this->persistStorage('C:\\Users\\bob\\Downloads');
        $currentStorage = $this->persistStorage('Z:\\Elsewhere');
        $anime = $this->persistAnime($currentStorage, 'some-release');
        $download = $this->persistCompletedDownload($anime, $snapshotStorage, 'some-release');

        $released = $this->pointer->releaseIfOwnedBy($download);

        $this->assertFalse($released);
        $this->assertSame($currentStorage->id, $anime->getStorage()?->id);
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    public function testKeepsThePointerWhenThePathDiffers(): void
    {
        $storage = $this->persistStorage('C:\\Users\\bob\\Downloads');
        $anime = $this->persistAnime($storage, 'a-different-release');
        $download = $this->persistCompletedDownload($anime, $storage, 'some-release');

        $released = $this->pointer->releaseIfOwnedBy($download);

        $this->assertFalse($released);
        $this->assertNotNull($anime->getStorage());
        $this->assertSame('a-different-release', $anime->getStoragePath());
    }

    public function testKeepsThePointerForALegacyRowWithNoSnapshot(): void
    {
        $storage = $this->persistStorage('C:\\Users\\bob\\Downloads');
        $anime = $this->persistAnime($storage, 'some-release');
        // Completed before issue #837 introduced the snapshot columns: both are NULL.
        $download = $this->persistCompletedDownload($anime, null, null);

        $released = $this->pointer->releaseIfOwnedBy($download);

        $this->assertFalse($released);
        $this->assertNotNull($anime->getStorage());
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    public function testKeepsAnAlreadyUnsetPointer(): void
    {
        $storage = $this->persistStorage('C:\\Users\\bob\\Downloads');
        $anime = $this->persistAnime(null, null);
        $download = $this->persistCompletedDownload($anime, $storage, 'some-release');

        $released = $this->pointer->releaseIfOwnedBy($download);

        $this->assertFalse($released);
        $this->assertNull($anime->getStorage());
        $this->assertNull($anime->getStoragePath());
    }
}
