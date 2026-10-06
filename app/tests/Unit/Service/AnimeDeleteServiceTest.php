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

namespace App\Tests\Unit\Service;

use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\RemoveFromSourceMessage;
use App\Message\SyncSeedMessage;
use App\Repository\SyncTombstoneRepository;
use App\Service\AnimeDeleteOutcome;
use App\Tests\Support\BuildsAnimeDeleteService;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AnimeDeleteServiceTest extends TestCase
{
    use BuildsAnimeDeleteService;
    use TemporaryDirectories;

    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityManager $entityManager;
    private string $mediaDir;

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
        // The real schema has ON DELETE CASCADE and the rows of an entry rely on it.
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->mediaDir = $this->createTemporaryDirectory('anime-delete-media-');
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    /** @param array<string, string> $externalIds plugin id => external id */
    private function persistAnime(string $title = 'Cowboy Bebop', array $externalIds = []): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        foreach ($externalIds as $pluginId => $externalId) {
            $anime->rememberExternalId(new PluginId($pluginId), $externalId);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function mediaFile(Anime $anime): string
    {
        $directory = $this->mediaDir.'/'.$anime->id;
        mkdir($directory);
        file_put_contents($directory.'/cover.webp', 'x');

        return $directory;
    }

    private function addDownload(Anime $anime, string $hash, string $status, ?string $failureReason = null): void
    {
        $download = new Download($hash, $anime);
        if ($status === 'completed') {
            $download->markCompleted();
        } elseif ($status === 'failed') {
            $download->markFailed($failureReason);
        }
        $this->entityManager->persist($download);
        $this->entityManager->flush();
    }

    /** @return list<array<string, mixed>> */
    private function tombstones(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT plugin_id, external_id FROM sync_tombstone ORDER BY plugin_id');
    }

    private function animeCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime');
    }

    public function testDeletesTheEntryItsMediaDirectoryAndWritesATombstonePerExternalId(): void
    {
        $anime = $this->persistAnime(externalIds: ['animedb-shikimori' => '1', 'animedb-mal' => '2']);
        $directory = $this->mediaFile($anime);

        $outcome = $this->newAnimeDeleteService($this->mediaDir)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame(0, $this->animeCount());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime_external_id'));
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(
            [['plugin_id' => 'animedb-mal', 'external_id' => '2'], ['plugin_id' => 'animedb-shikimori', 'external_id' => '1']],
            $this->tombstones(),
        );
    }

    /** @return array<string, bool> "plugin:external" => pending flag */
    private function pendingFlags(): array
    {
        $flags = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT plugin_id, external_id, removal_pending FROM sync_tombstone') as $row) {
            $flags[$row['plugin_id'].':'.$row['external_id']] = (bool) $row['removal_pending'];
        }
        ksort($flags);

        return $flags;
    }

    /**
     * @param list<string> $exclude
     *
     * @return list<object>
     */
    private function deleteWithRecordingBus(Anime $anime, bool $removeFromSources, array $exclude = []): array
    {
        $messages = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });
        $registry = $this->newSyncRegistryOf([
            'acme-remove' => $this->createStub(SyncRemovalInterface::class),
            'acme-also' => $this->createStub(SyncRemovalInterface::class),
            'acme-plain' => $this->createStub(SyncInterface::class),
            'acme-off' => $this->createStub(SyncRemovalInterface::class),
        ], ['acme-remove', 'acme-also', 'acme-plain']);

        $this->newAnimeDeleteService($this->mediaDir, $registry, bus: $bus)->delete($anime, $removeFromSources, $exclude);

        return $messages;
    }

    public function testWithTheOptionTheTargetTombstonesGetTheFlagAndOneMessagePerTargetIsQueued(): void
    {
        $anime = $this->persistAnime(externalIds: ['acme-remove' => '1', 'acme-also' => '2', 'acme-plain' => '3', 'acme-off' => '4']);

        $messages = $this->deleteWithRecordingBus($anime, true);

        $this->assertSame(['acme-also:2' => true, 'acme-off:4' => false, 'acme-plain:3' => false, 'acme-remove:1' => true], $this->pendingFlags());
        $this->assertEquals([new RemoveFromSourceMessage('acme-remove', '1'), new RemoveFromSourceMessage('acme-also', '2')], $messages);
    }

    public function testWithoutTheOptionNoFlagIsSetAndNothingIsQueued(): void
    {
        $anime = $this->persistAnime(externalIds: ['acme-remove' => '1']);

        $messages = $this->deleteWithRecordingBus($anime, false);

        $this->assertSame(['acme-remove:1' => false], $this->pendingFlags());
        $this->assertSame([], $messages);
    }

    public function testAnExcludedSourceIsNotATargetEvenWithTheOption(): void
    {
        $anime = $this->persistAnime(externalIds: ['acme-remove' => '1', 'acme-also' => '2']);

        $messages = $this->deleteWithRecordingBus($anime, true, ['acme-remove']);

        $this->assertSame(['acme-also:2' => true, 'acme-remove:1' => false], $this->pendingFlags());
        $this->assertEquals([new RemoveFromSourceMessage('acme-also', '2')], $messages);
    }

    public function testNothingIsFlaggedOrQueuedWhenTheDeletionIsRefused(): void
    {
        $anime = $this->persistAnime(externalIds: ['acme-remove' => '1']);
        $this->addDownload($anime, self::HASH_A, 'pending');

        $messages = $this->deleteWithRecordingBus($anime, true);

        $this->assertSame([], $this->pendingFlags());
        $this->assertSame([], $messages);
    }

    public function testAQueueFailureIsLoggedAndTheDeletionStandsWithItsFlag(): void
    {
        $anime = $this->persistAnime(externalIds: ['acme-remove' => '1']);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('queue down'));
        $registry = $this->newSyncRegistryOf(['acme-remove' => $this->createStub(SyncRemovalInterface::class)], ['acme-remove']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $outcome = $this->newAnimeDeleteService($this->mediaDir, $registry, logger: $logger, bus: $bus)->delete($anime, true);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame(['acme-remove:1' => true], $this->pendingFlags());
    }

    public function testVideoFilesInTheStorageAreNotTouched(): void
    {
        $storageDir = $this->createTemporaryDirectory('anime-delete-storage-');
        file_put_contents($storageDir.'/Cowboy Bebop.mkv', 'video');
        $storage = new Storage('Main', $storageDir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $anime = $this->persistAnime();
        $anime->setStorage($storage)->setStoragePath('Cowboy Bebop.mkv');
        $this->entityManager->flush();

        $this->newAnimeDeleteService($this->mediaDir)->delete($anime);

        $this->assertSame(0, $this->animeCount());
        $this->assertFileExists($storageDir.'/Cowboy Bebop.mkv');
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM storage'));
    }

    public function testAPendingDownloadRefusesTheDeletionAndChangesNothing(): void
    {
        $anime = $this->persistAnime(externalIds: ['animedb-shikimori' => '1']);
        $directory = $this->mediaFile($anime);
        $this->addDownload($anime, self::HASH_A, 'pending');
        $this->addDownload($anime, self::HASH_B, 'completed');

        $outcome = $this->newAnimeDeleteService($this->mediaDir)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::PendingDownloads, $outcome);
        $this->assertSame(1, $this->animeCount());
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM downloads'));
        $this->assertDirectoryExists($directory);
        $this->assertSame([], $this->tombstones());
        $this->assertSame([], $this->qbittorrentRequests, 'No torrent may be touched when the deletion is refused.');
    }

    public function testCompletedAndFailedDownloadsAreRemovedFromTheClientWithoutTheirFiles(): void
    {
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'completed');
        $this->addDownload($anime, self::HASH_B, 'failed');
        $client = $this->newQbittorrentClient(torrents: [
            ['hash' => self::HASH_A, 'infohash_v1' => self::HASH_A, 'content_path' => '/library/Bebop'],
            ['hash' => self::HASH_B, 'infohash_v1' => self::HASH_B, 'content_path' => '/library/Other'],
        ]);

        $outcome = $this->newAnimeDeleteService($this->mediaDir, qbittorrent: $client)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame([
            'GET http://qb.test/api/v2/torrents/info ',
            'POST http://qb.test/api/v2/torrents/delete hashes='.self::HASH_A.'&deleteFiles=false',
            'POST http://qb.test/api/v2/torrents/delete hashes='.self::HASH_B.'&deleteFiles=false',
        ], $this->qbittorrentRequests);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM downloads'));
    }

    public function testAHybridTorrentIsDeletedByTheClientsHashNotByTheV1Hash(): void
    {
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'completed');
        $client = $this->newQbittorrentClient(torrents: [
            ['hash' => 'cccccccccccccccccccccccccccccccccccccccc', 'infohash_v1' => strtoupper(self::HASH_A), 'content_path' => '/library/Bebop'],
        ]);

        $this->newAnimeDeleteService($this->mediaDir, qbittorrent: $client)->delete($anime);

        $this->assertSame([
            'GET http://qb.test/api/v2/torrents/info ',
            'POST http://qb.test/api/v2/torrents/delete hashes=cccccccccccccccccccccccccccccccccccccccc&deleteFiles=false',
        ], $this->qbittorrentRequests);
    }

    public function testAFailedDownloadWithDataInHiddenIncomingKeepsItsTorrentInTheClient(): void
    {
        $storageDir = $this->createTemporaryDirectory('anime-delete-storage-');
        $this->entityManager->persist(new Storage('Main', $storageDir, StorageType::Folder));
        $this->entityManager->flush();
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'failed', 'name_conflict');
        $this->addDownload($anime, self::HASH_B, 'completed');
        $client = $this->newQbittorrentClient(torrents: [
            ['hash' => self::HASH_A, 'infohash_v1' => self::HASH_A, 'content_path' => $storageDir.'/.anime-db/incoming/'.self::HASH_A.'/Bebop'],
            ['hash' => self::HASH_B, 'infohash_v1' => self::HASH_B, 'content_path' => $storageDir.'/Bebop 2'],
        ]);

        $outcome = $this->newAnimeDeleteService($this->mediaDir, qbittorrent: $client)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame(0, $this->animeCount());
        $this->assertSame([
            'GET http://qb.test/api/v2/torrents/info ',
            'POST http://qb.test/api/v2/torrents/delete hashes='.self::HASH_B.'&deleteFiles=false',
        ], $this->qbittorrentRequests);
    }

    public function testATorrentMissingFromTheClientIsSkipped(): void
    {
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'completed');

        $outcome = $this->newAnimeDeleteService($this->mediaDir)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame(0, $this->animeCount());
        $this->assertSame(['GET http://qb.test/api/v2/torrents/info '], $this->qbittorrentRequests);
    }

    public function testAClientFailureIsLoggedAndDoesNotUndoTheDeletion(): void
    {
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'completed');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('could not be looked up'));

        $outcome = $this->newAnimeDeleteService($this->mediaDir, qbittorrent: $this->newQbittorrentClient(failing: true), logger: $logger)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame(0, $this->animeCount());
    }

    public function testAFailedTorrentRemovalIsLoggedAndDoesNotUndoTheDeletion(): void
    {
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'completed');
        $client = $this->newQbittorrentClient(
            torrents: [['hash' => self::HASH_A, 'infohash_v1' => self::HASH_A, 'content_path' => '/library/Bebop']],
            failingPaths: ['/torrents/delete'],
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('could not be removed'));

        $outcome = $this->newAnimeDeleteService($this->mediaDir, qbittorrent: $client, logger: $logger)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
        $this->assertSame(0, $this->animeCount());
    }

    public function testACompletedDownloadWithDataInHiddenIncomingIsStillRemovedFromTheClient(): void
    {
        $storageDir = $this->createTemporaryDirectory('anime-delete-storage-');
        $this->entityManager->persist(new Storage('Main', $storageDir, StorageType::Folder));
        $this->entityManager->flush();
        $anime = $this->persistAnime();
        $this->addDownload($anime, self::HASH_A, 'completed');
        $client = $this->newQbittorrentClient(torrents: [
            ['hash' => self::HASH_A, 'infohash_v1' => self::HASH_A, 'content_path' => $storageDir.'/.anime-db/incoming/'.self::HASH_A.'/Bebop'],
        ]);

        $this->newAnimeDeleteService($this->mediaDir, qbittorrent: $client)->delete($anime);

        $this->assertSame([
            'GET http://qb.test/api/v2/torrents/info ',
            'POST http://qb.test/api/v2/torrents/delete hashes='.self::HASH_A.'&deleteFiles=false',
        ], $this->qbittorrentRequests);
    }

    public function testARunningSyncOfAnActivePluginRefusesTheDeletion(): void
    {
        $anime = $this->persistAnime(externalIds: ['animedb-shikimori' => '1']);
        $jobLock = $this->newJobLockService();
        $this->assertTrue($jobLock->acquire(SyncSeedMessage::jobKey('animedb-shikimori')));

        $outcome = $this->newAnimeDeleteService($this->mediaDir, $this->newSyncRegistryWithActive(['animedb-shikimori']), $jobLock)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::SyncRunning, $outcome);
        $this->assertSame(1, $this->animeCount());
        $this->assertSame([], $this->tombstones());
    }

    public function testALockOfAPluginWithSyncSwitchedOffDoesNotRefuseTheDeletion(): void
    {
        $anime = $this->persistAnime();
        $jobLock = $this->newJobLockService();
        $jobLock->acquire(SyncSeedMessage::jobKey('animedb-shikimori'));

        $outcome = $this->newAnimeDeleteService($this->mediaDir, $this->newSyncRegistryWithActive([]), $jobLock)->delete($anime);

        $this->assertSame(AnimeDeleteOutcome::Deleted, $outcome);
    }

    public function testDeletingTheSameTitleAgainAfterReAddingItDoesNotFailOnThePrimaryKey(): void
    {
        $service = $this->newAnimeDeleteService($this->mediaDir);

        $first = $this->persistAnime(externalIds: ['animedb-shikimori' => '1']);
        $this->assertSame(AnimeDeleteOutcome::Deleted, $service->delete($first));

        $second = $this->persistAnime(externalIds: ['animedb-shikimori' => '1']);
        $this->assertSame(AnimeDeleteOutcome::Deleted, $service->delete($second));

        $this->assertSame([['plugin_id' => 'animedb-shikimori', 'external_id' => '1']], $this->tombstones());
        $this->assertTrue((new SyncTombstoneRepository($this->entityManager))->exists('animedb-shikimori', '1'));
    }

    public function testUnresolvedReviewItemsAreTidied(): void
    {
        $anime = $this->persistAnime();
        $animeId = $anime->id;
        $this->addDownload($anime, self::HASH_A, 'completed');
        $single = new SyncReviewItem(SyncReviewItemKind::DeletedFromSource, ['anime_id' => $animeId, 'deleted_from' => 'animedb-shikimori']);
        $duplicate = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [$animeId, 901, 902]]);
        $otherEntry = new SyncReviewItem(SyncReviewItemKind::DeletedFromSource, ['anime_id' => 901, 'deleted_from' => 'animedb-shikimori']);
        foreach ([$single, $duplicate, $otherEntry] as $item) {
            $this->entityManager->persist($item);
        }
        $this->entityManager->flush();
        $ids = [$single->id ?? 0, $duplicate->id ?? 0, $otherEntry->id ?? 0];

        $this->newAnimeDeleteService($this->mediaDir)->delete($anime);

        $this->entityManager->clear();
        [$single, $duplicate, $otherEntry] = array_map(
            fn (int $id): SyncReviewItem => $this->entityManager->find(SyncReviewItem::class, $id) ?? throw new \LogicException('Item vanished.'),
            $ids,
        );
        $this->assertTrue($single->isResolved());
        $this->assertFalse($duplicate->isResolved());
        $this->assertSame([901, 902], $duplicate->payload['anime_ids']);
        $this->assertFalse($otherEntry->isResolved());
    }

    public function testADuplicateItemLeftWithFewerThanTwoEntriesIsClosed(): void
    {
        $anime = $this->persistAnime();
        $duplicate = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [$anime->id, 901]]);
        $this->entityManager->persist($duplicate);
        $this->entityManager->flush();
        $id = $duplicate->id;

        $this->newAnimeDeleteService($this->mediaDir)->delete($anime);

        $this->entityManager->clear();
        $this->assertTrue($this->entityManager->find(SyncReviewItem::class, $id)?->isResolved());
    }
}
