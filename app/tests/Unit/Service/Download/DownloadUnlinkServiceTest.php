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
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadFolderPointer;
use App\Service\Download\DownloadUnlinkService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class DownloadUnlinkServiceTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $repository;
    private DownloadUnlinkService $service;

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

        $this->repository = new DownloadRepository($this->entityManager);
        $this->service = new DownloadUnlinkService(new DownloadFolderPointer(), $this->entityManager);
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Anime A')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function persistStorage(): Storage
    {
        $storage = new Storage('Downloads', 'C:\\Users\\bob\\Downloads', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    public function testUnlinkDeletesTheRowAndReportsNoPointerToRelease(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $result = $this->service->unlink($download);

        $this->assertTrue($result->succeeded);
        $this->assertFalse($result->pointerReleased);
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    public function testUnlinkDeletesTheRowAndReleasesAMatchingPointer(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();

        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $download->recordLinkedStorage($storage, 'some-release');
        $this->repository->save($download);

        $result = $this->service->unlink($download);

        $this->assertTrue($result->succeeded);
        $this->assertTrue($result->pointerReleased);
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $this->entityManager->refresh($anime);
        $this->assertNull($anime->getStorage());
    }

    /**
     * Guards the optimistic-lock race with DownloadCompletionPoller (issue #837): a version that no
     * longer matches must roll the whole transaction back, including any pointer clear it staged,
     * not just skip the DELETE.
     */
    public function testUnlinkRollsBackWhenTheRowVersionChangedConcurrently(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();

        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $download->recordLinkedStorage($storage, 'some-release');
        $this->repository->save($download);

        // Simulates a concurrent writer (the poller) touching the row after it was read here.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET version = version + 1 WHERE id = ?',
            [$download->id],
        );

        $result = $this->service->unlink($download);

        $this->assertFalse($result->succeeded);
        $this->assertFalse($result->pointerReleased);

        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $reloadedAnime = $this->entityManager->find(TvAnime::class, $anime->id);
        $this->assertNotNull($reloadedAnime);
        $this->assertSame($storage->id, $reloadedAnime->getStorage()?->id);
        $this->assertSame('some-release', $reloadedAnime->getStoragePath());
    }
}
