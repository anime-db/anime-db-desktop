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
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadFolderPointer;
use App\Service\Download\DownloadUnlinkService;
use App\Tests\Fixtures\Service\Download\ThrowingOnDeleteConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $download->markCompleted();
        $this->repository->save($download);

        $result = $this->service->unlink($download, $download->getVersion(), DownloadStatus::Completed);

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

        $result = $this->service->unlink($download, $download->getVersion(), DownloadStatus::Completed);

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

        $result = $this->service->unlink($download, $download->getVersion(), DownloadStatus::Completed);

        $this->assertFalse($result->succeeded);
        $this->assertFalse($result->pointerReleased);

        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $reloadedAnime = $this->entityManager->find(TvAnime::class, $anime->id);
        $this->assertNotNull($reloadedAnime);
        $this->assertSame($storage->id, $reloadedAnime->getStorage()?->id);
        $this->assertSame('some-release', $reloadedAnime->getStoragePath());
    }

    /**
     * The $download and $anime instances passed in are the same ones the caller (the HTTP
     * controller or the console command) goes on to re-render or re-flush after a version conflict.
     * Rolling back the DB transaction does not undo the pointer-release mutation already applied to
     * the in-memory $anime, nor does it refresh $download's stale status — both must be reloaded
     * from the DB the rollback actually left behind, or the caller shows a lie (issue #857 review).
     */
    public function testUnlinkRefreshesTheDownloadAndAnimeAfterAVersionConflict(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();

        $download = new Download(self::HASH, $anime);
        $download->recordLinkedStorage($storage, 'some-release');
        $this->repository->save($download);

        // Simulates DownloadCompletionPoller completing the row from a separate process after this
        // request already loaded $download as still Pending.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET status = ?, version = version + 1 WHERE id = ?',
            [DownloadStatus::Completed->value, $download->id],
        );

        $result = $this->service->unlink($download, $download->getVersion(), DownloadStatus::Completed);

        $this->assertFalse($result->succeeded);
        $this->assertFalse($result->refused);
        $this->assertSame(DownloadStatus::Completed, $download->getStatus());
        $this->assertSame($storage->id, $anime->getStorage()?->id);
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    /**
     * A failure mid-transaction (flush() or the DELETE itself throwing, e.g. SQLITE_BUSY from
     * DownloadCompletionPoller writing the same row concurrently) must not leave the transaction
     * open on the connection — FrankenPHP's worker mode reuses this connection across requests, so a
     * leaked transaction would make every later commit() in the same worker a no-op (issue #857
     * review).
     */
    public function testUnlinkRollsBackTheTransactionWhenTheDeleteThrows(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => ThrowingOnDeleteConnection::class],
            $config,
        );
        $entityManager = new EntityManager($connection, $config);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $repository = new DownloadRepository($entityManager);
        $service = new DownloadUnlinkService(new DownloadFolderPointer(), $entityManager);

        $anime = new TvAnime();
        $anime->setTitle('Anime A')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $repository->save($download);

        $this->expectException(\RuntimeException::class);

        try {
            $service->unlink($download, $download->getVersion(), DownloadStatus::Completed);
        } finally {
            $this->assertFalse($connection->isTransactionActive());
        }
    }

    #[DataProvider('unfinishedStatuses')]
    public function testUnlinkRefusesANonCompletedExpectedStatusWithoutTouchingAnything(DownloadStatus $status): void
    {
        $anime = $this->persistAnime();
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();

        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $download->recordLinkedStorage($storage, 'some-release');
        $this->repository->save($download);

        $result = $this->service->unlink($download, $download->getVersion(), $status);

        $this->assertFalse($result->succeeded);
        $this->assertTrue($result->refused);
        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $reloaded = $this->entityManager->find(TvAnime::class, $anime->id);
        $this->assertSame($storage->id, $reloaded?->getStorage()?->id);
        $this->assertSame('some-release', $reloaded?->getStoragePath());
    }

    /** @return iterable<string, array{DownloadStatus}> */
    public static function unfinishedStatuses(): iterable
    {
        yield 'pending' => [DownloadStatus::Pending];
        yield 'failed' => [DownloadStatus::Failed];
    }

    /**
     * The page was rendered while the row was Pending (version N); the poller then completed it and
     * set the pointer (version N+1). The stale POST (N, pending) must be refused and leave both alone.
     */
    public function testStalePendingFormAgainstACompletedRowIsRefused(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->persistStorage();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);
        $seenVersion = $download->getVersion();

        $download->markCompleted();
        $download->recordLinkedStorage($storage, 'some-release');
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();
        $this->assertGreaterThan($seenVersion, $download->getVersion());

        $result = $this->service->unlink($download, $seenVersion, DownloadStatus::Pending);

        $this->assertTrue($result->refused);
        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $this->assertSame('some-release', $this->entityManager->find(TvAnime::class, $anime->id)?->getStoragePath());
    }

    public function testStaleVersionWithCompletedStatusIsAConflictAndKeepsRowAndPointer(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $download->recordLinkedStorage($storage, 'some-release');
        $this->repository->save($download);
        $seenVersion = $download->getVersion();

        $this->entityManager->getConnection()->executeStatement('UPDATE downloads SET version = version + 1 WHERE id = ?', [$download->id]);

        $result = $this->service->unlink($download, $seenVersion, DownloadStatus::Completed);

        $this->assertFalse($result->succeeded);
        $this->assertFalse($result->refused);
        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $this->assertSame('some-release', $this->entityManager->find(TvAnime::class, $anime->id)?->getStoragePath());
    }
}
