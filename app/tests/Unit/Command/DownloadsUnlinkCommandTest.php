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

namespace App\Tests\Unit\Command;

use App\Command\DownloadsUnlinkCommand;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadFolderPointer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DownloadsUnlinkCommandTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ROOT = 'C:\\Users\\bob\\Downloads';

    private EntityManager $entityManager;
    private DownloadRepository $repository;
    private CommandTester $tester;

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
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new DownloadRepository($this->entityManager);
        $this->tester = new CommandTester($this->makeCommand($this->repository));
    }

    private function makeCommand(DownloadRepository $repository): DownloadsUnlinkCommand
    {
        return new DownloadsUnlinkCommand($repository, new DownloadFolderPointer(), $this->entityManager);
    }

    private function persistAnime(string $title): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function persistStorage(string $path = self::ROOT): Storage
    {
        $storage = new Storage('Downloads', $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    /**
     * Persists a Download already in the Completed state with a given completion snapshot, as if
     * AnimeDownloadLinker::link() had already run for it — without going through the poller, since
     * these tests are about the unlink command, not completion itself.
     */
    private function persistCompletedDownload(string $infoHash, TvAnime $anime, ?Storage $snapshotStorage, ?string $snapshotPath): Download
    {
        $download = new Download($infoHash, $anime);
        $download->markCompleted();
        if ($snapshotStorage !== null && $snapshotPath !== null) {
            $download->recordLinkedStorage($snapshotStorage, $snapshotPath);
        }
        $this->entityManager->persist($download);
        $this->entityManager->flush();

        return $download;
    }

    public function testUnlinkRemovesThePairingAndKeepsTheAnime(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH, $anime));

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $this->assertNotNull($this->entityManager->find(TvAnime::class, $anime->id));
    }

    public function testUnlinkedPairingCanBeCreatedAgain(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH, $anime));

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);
        $this->assertSame(Command::SUCCESS, $exit);

        // Without the unlink this would violate uniq_download_infohash_anime.
        $this->repository->save(new Download(self::HASH, $anime));

        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    public function testInfoHashIsMatchedCaseInsensitively(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH, $anime));

        $exit = $this->tester->execute(['info-hash' => strtoupper(self::HASH), 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    public function testMissingPairingFailsWithMessage(): void
    {
        $anime = $this->persistAnime('Anime A');

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('No pairing found', $this->tester->getDisplay());
    }

    public function testNonNumericAnimeIdFails(): void
    {
        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => 'abc']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('must be a non-negative integer', $this->tester->getDisplay());
    }

    public function testUnlinkClearsTheAnimePointerWhenItMatchesTheDownloadsSnapshot(): void
    {
        $anime = $this->persistAnime('Anime A');
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();
        $this->persistCompletedDownload(self::HASH, $anime, $storage, 'some-release');

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('storage folder pointer was removed', $this->tester->getDisplay());
        $this->entityManager->refresh($anime);
        $this->assertNull($anime->getStorage());
        $this->assertNull($anime->getStoragePath());
    }

    public function testUnlinkKeepsTheAnimePointerWhenItPointsAtADifferentStorage(): void
    {
        $anime = $this->persistAnime('Anime A');
        $linkedStorage = $this->persistStorage(self::ROOT);
        $currentStorage = $this->persistStorage('Z:\\Elsewhere');
        $anime->setStorage($currentStorage)->setStoragePath('some-release');
        $this->entityManager->flush();
        $this->persistCompletedDownload(self::HASH, $anime, $linkedStorage, 'some-release');

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('storage folder pointer was kept', $this->tester->getDisplay());
        $this->entityManager->refresh($anime);
        $this->assertSame($currentStorage->id, $anime->getStorage()?->id);
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    public function testUnlinkKeepsTheAnimePointerWhenItPointsAtADifferentPath(): void
    {
        $anime = $this->persistAnime('Anime A');
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('a-different-release');
        $this->entityManager->flush();
        $this->persistCompletedDownload(self::HASH, $anime, $storage, 'some-release');

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('storage folder pointer was kept', $this->tester->getDisplay());
        $this->entityManager->refresh($anime);
        $this->assertNotNull($anime->getStorage());
        $this->assertSame('a-different-release', $anime->getStoragePath());
    }

    public function testUnlinkKeepsTheAnimePointerForALegacyRowWithNoSnapshot(): void
    {
        $anime = $this->persistAnime('Anime A');
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();
        // Completed before this feature existed: no snapshot was ever recorded.
        $this->persistCompletedDownload(self::HASH, $anime, null, null);

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('storage folder pointer was kept', $this->tester->getDisplay());
        $this->entityManager->refresh($anime);
        $this->assertNotNull($anime->getStorage());
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    public function testDownloadFreedByUnlinkCanBeRelinkedToAnotherAnimeWithoutConflict(): void
    {
        $animeA = $this->persistAnime('Anime A');
        $storage = $this->persistStorage();
        $animeA->setStorage($storage)->setStoragePath('shared-pack');
        $this->entityManager->flush();
        $this->persistCompletedDownload(self::HASH, $animeA, $storage, 'shared-pack');

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $animeA->id]);
        $this->assertSame(Command::SUCCESS, $exit);

        $animeB = $this->persistAnime('Anime B');
        $newDownload = new Download(self::HASH, $animeB);
        $newDownload->assignTargetStorage($storage);
        $this->entityManager->persist($newDownload);
        $this->entityManager->flush();

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(
            new AnimeRepository($this->entityManager),
            $this->entityManager,
            $jail,
        );

        // Would throw DownloadStoragePathConflictException before the unlink above freed the
        // (storage, path) pair — this is the whole point of issue #837.
        $linker->link($newDownload, self::ROOT.'\\shared-pack');

        $this->assertSame('shared-pack', $animeB->getStoragePath());
    }

    public function testUnlinkRollsBackTheClearedPointerWhenTheRowVersionChangedConcurrently(): void
    {
        $anime = $this->persistAnime('Anime A');
        $storage = $this->persistStorage();
        $anime->setStorage($storage)->setStoragePath('some-release');
        $this->entityManager->flush();
        $this->persistCompletedDownload(self::HASH, $anime, $storage, 'some-release');

        // A repository whose findByInfoHashAndAnime() hands the command a row it just read, but
        // then — simulating a concurrent writer touching the same row, exactly as issue #837's
        // race between DownloadCompletionPoller and app:downloads:unlink describes — bumps that
        // row's version directly in the database before the command gets to delete it. The
        // in-memory Download the command works with already carries a snapshot matching the
        // anime's pointer, so releaseIfOwnedBy() clears it and flush() writes that clear inside
        // the transaction; the version-checked DELETE below must then fail and roll the whole
        // transaction back, including that pointer clear.
        $connection = $this->entityManager->getConnection();
        $racedRepository = new class($this->entityManager, $connection) extends DownloadRepository {
            public function __construct(
                EntityManagerInterface $entityManager,
                private readonly Connection $connection,
            ) {
                parent::__construct($entityManager);
            }

            public function findByInfoHashAndAnime(string $infoHash, int $animeId): ?Download
            {
                $download = parent::findByInfoHashAndAnime($infoHash, $animeId);
                if ($download !== null) {
                    $this->connection->executeStatement('UPDATE downloads SET version = version + 1 WHERE id = ?', [$download->id]);
                }

                return $download;
            }
        };

        $tester = new CommandTester($this->makeCommand($racedRepository));
        $exit = $tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('please retry', $tester->getDisplay());

        $this->entityManager->clear();
        $stillThere = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stillThere);
        $this->assertTrue($stillThere->isCompleted());
        $this->assertSame(2, $stillThere->getVersion());

        // Read back from the database (not the detached in-memory $anime) to prove the rollback
        // actually undid the pointer clear, not just that the DELETE was skipped.
        $reloadedAnime = $this->entityManager->find(TvAnime::class, $anime->id);
        $this->assertNotNull($reloadedAnime);
        $this->assertSame($storage->id, $reloadedAnime->getStorage()?->id);
        $this->assertSame('some-release', $reloadedAnime->getStoragePath());
    }
}
