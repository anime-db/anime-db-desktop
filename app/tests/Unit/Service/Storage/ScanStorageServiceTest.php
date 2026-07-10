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

namespace App\Tests\Unit\Service\Storage;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Service\Storage\FilenameCleaner;
use App\Service\Storage\OrphanAnimeMatcher;
use App\Service\Storage\Scan\ScanItemType;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\Search\NullSearchByPlugin;
use App\Service\Storage\Search\SearchByPluginCandidate;
use App\Service\Storage\Search\SearchByPluginChain;
use App\Service\Storage\Search\SearchByPluginInterface;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ScanStorageService end to end (real EntityManager/SQLite connection, real
 * filesystem in a temp dir) — same setup as OrphanAnimeMatcherTest/StorageMarkerServiceTest,
 * combined: the 0/1/>1 candidate rule (Таск 3 часть 5) needs both the catalog and the disk
 * to be real to be meaningfully tested.
 */
final class ScanStorageServiceTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeRepository $animeRepository;

    /** @var list<string> */
    private array $dirsToClean = [];

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

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->animeRepository = new AnimeRepository($this->entityManager);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function makeStorageDir(): string
    {
        $dir = sys_get_temp_dir().'/scan-storage-test-'.uniqid();
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    private function touchFile(string $path, ?int $mtime = null): void
    {
        file_put_contents($path, 'x');
        touch($path, $mtime ?? time());
    }

    private function newService(?SearchByPluginChain $pluginChain = null): ScanStorageService
    {
        return new ScanStorageService(
            new StorageMarkerService($this->entityManager),
            new FilenameCleaner(),
            new OrphanAnimeMatcher($this->animeRepository),
            $pluginChain ?? new SearchByPluginChain([new NullSearchByPlugin()]),
            $this->animeRepository,
            $this->entityManager,
        );
    }

    private function pluginChainReturning(?SearchByPluginCandidate $candidate): SearchByPluginChain
    {
        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($candidate);

        return new SearchByPluginChain([$plugin]);
    }

    public function testConflictWhenMarkerOwnedByAnotherActiveStorageAbortsScanWithNoSideEffects(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/New.mkv');

        $owner = new Storage('Owner', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($owner);
        $this->entityManager->flush();

        file_put_contents($dir.'/desktop.ini', "[AnimeDB]\nid={$owner->id}\n");

        $storage = new Storage('Target', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertTrue($result->conflicted);
        $this->assertSame([], $result->items);
        $this->assertNull($storage->getDateUpdate());
        $sections = parse_ini_file($dir.'/desktop.ini', true, \INI_SCANNER_RAW);
        $this->assertIsArray($sections);
        $this->assertSame((string) $owner->id, $sections['AnimeDB']['id']);
    }

    public function testNonScannableStorageTypeIsSkippedWithoutTouchingTheFilesystem(): void
    {
        $dir = $this->makeStorageDir();

        $storage = new Storage('Optical disc', $dir, StorageType::ExternalR);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertFalse($result->conflicted);
        $this->assertSame([], $result->items);
        $this->assertFileDoesNotExist($dir.'/desktop.ini');
    }

    public function testUnchangedLinkedFileProducesNoItem(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv', time() - 100);

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertSame([], $result->items);
    }

    public function testFileModifiedAfterLastUpdateProducesUpdatedItem(): void
    {
        $dir = $this->makeStorageDir();
        $filePath = $dir.'/Trigun.mkv';
        $this->touchFile($filePath, time() - 100);

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        touch($filePath, time() + 100);

        $result = $this->newService()->scan($storage);

        $this->assertCount(1, $result->items);
        $this->assertSame(ScanItemType::Updated, $result->items[0]->type);
        $this->assertSame($anime, $result->items[0]->anime);
        $this->assertSame('Trigun.mkv', $result->items[0]->storagePath);
    }

    public function testLinkedAnimeWithoutMatchingFileOnDiskProducesFilesMissingItem(): void
    {
        $dir = $this->makeStorageDir();

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertCount(1, $result->items);
        $this->assertSame(ScanItemType::FilesMissing, $result->items[0]->type);
        $this->assertSame($anime, $result->items[0]->anime);
        $this->assertSame('Trigun.mkv', $result->items[0]->storagePath);
    }

    public function testNewFileWithNoCandidatesProducesNeedsManualEntry(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::NeedsManualEntry, $item->type);
        $this->assertSame('Trigun.mkv', $item->storagePath);
        $this->assertSame('Trigun', $item->cleanedName);
        $this->assertNull($item->anime);
    }

    public function testNewFileNotMatchingAnyWhitelistedExtensionIsIgnored(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/readme.txt');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertSame([], $result->items);
    }

    public function testOnlyTopLevelEntriesAreConsideredNotNestedFiles(): void
    {
        $dir = $this->makeStorageDir();
        mkdir($dir.'/MySeries');
        $this->touchFile($dir.'/MySeries/episode1.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertCount(1, $result->items);
        $this->assertSame('MySeries', $result->items[0]->storagePath);
    }

    public function testNewFileWithExactlyOneOrphanCandidateIsAutoLinkedToTheExistingAnime(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $result = $this->newService()->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $this->assertSame($orphan, $item->anime);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $orphanId);
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    public function testNewFileWithExactlyOnePluginCandidateCreatesANewAnime(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $candidate = new SearchByPluginCandidate(new PluginId('animedb-test'), 'Trigun');
        $service = $this->newService($this->pluginChainReturning($candidate));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $this->assertNotNull($item->anime);
        $this->assertSame('Trigun', $item->anime->getTitle());
        $this->assertSame($storage->id, $item->anime->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $item->anime->getStoragePath());

        $animeId = $item->anime->id;
        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $animeId);
        $this->assertSame('Trigun', $reloaded->getTitle());
    }

    public function testExactlyOneOrphanConfirmedByExactlyOnePluginMatchIsAutoLinkedToTheOrphan(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $candidate = new SearchByPluginCandidate(new PluginId('animedb-test'), 'Trigun the Movie');
        $service = $this->newService($this->pluginChainReturning($candidate));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $this->assertSame($orphan, $item->anime);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $orphanId);
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    public function testMultipleOrphanCandidatesRequireConfirmation(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $first = new TvAnime();
        $first->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $second = new TvAnime();
        $second->setTitle('Trigun the Other One')->setWatchStatus(WatchStatus::Plan);
        $second->addName('Trigun', AnimeNameType::Synonym);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        $result = $this->newService()->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::NeedsConfirmation, $item->type);
        $this->assertCount(2, $item->candidates);
    }

    public function testMultipleOrphanCandidatesWithAPluginMatchStillRequireConfirmation(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $first = new TvAnime();
        $first->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $second = new TvAnime();
        $second->setTitle('Trigun the Other One')->setWatchStatus(WatchStatus::Plan);
        $second->addName('Trigun', AnimeNameType::Synonym);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        $candidate = new SearchByPluginCandidate(new PluginId('animedb-test'), 'Trigun the Movie');
        $service = $this->newService($this->pluginChainReturning($candidate));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::NeedsConfirmation, $item->type);
        $this->assertCount(3, $item->candidates);

        // None of the orphans should have been touched while awaiting confirmation.
        $this->assertNull($first->getStorage());
        $this->assertNull($second->getStorage());
    }

    public function testSuccessfulScanRecordsStorageScanTimestamps(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $this->newService()->scan($storage);

        $this->assertNotNull($storage->getDateUpdate());
        $this->assertNotNull($storage->getFileModified());
    }
}
