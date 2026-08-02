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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Model\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Storage\Exception\StoragePathConflictException;
use App\Service\Storage\FilenameCleaner;
use App\Service\Storage\OrphanAnimeMatcher;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanItemType;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\Search\SearchByPluginChain;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
            if ($entry === '.' || $entry === '..') {
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

    private function newService(?SearchByPluginChain $pluginChain = null, ?BulkFillerService $bulkFillerService = null, ?LoggerInterface $logger = null): ScanStorageService
    {
        return new ScanStorageService(
            new StorageMarkerService($this->entityManager),
            new FilenameCleaner(),
            new OrphanAnimeMatcher($this->animeRepository),
            $pluginChain ?? new SearchByPluginChain([], new PluginsConfigStore('')),
            $this->animeRepository,
            $this->entityManager,
            $bulkFillerService ?? $this->newBulkFillerService([]),
            $logger ?? new NullLogger(),
        );
    }

    /** @param iterable<string, FillerInterface> $fillers */
    private function newBulkFillerService(iterable $fillers = []): BulkFillerService
    {
        return new BulkFillerService(
            new FillerRegistry($fillers, new PluginsConfigStore('')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            new NullLogger(),
        );
    }

    private function pluginChainReturning(SearchByPluginCandidate $candidate): SearchByPluginChain
    {
        return $this->pluginChainReturningAll([$candidate]);
    }

    /** @param list<SearchByPluginCandidate> $candidates */
    private function pluginChainReturningAll(array $candidates): SearchByPluginChain
    {
        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($candidates);

        return new SearchByPluginChain(['test-plugin' => $plugin], new PluginsConfigStore(''));
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

    public function testRelocatesStorageWhenScannedAtPathWhereItsOwnMarkerHasMoved(): void
    {
        $oldDir = $this->makeStorageDir();
        $newDir = $this->makeStorageDir();
        $this->touchFile($newDir.'/New.mkv');

        $storage = new Storage('Main folder', $oldDir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        file_put_contents($newDir.'/desktop.ini', "[AnimeDB]\nid={$storage->id}\n");

        $result = $this->newService()->scan($storage, atPath: $newDir);

        $this->assertFalse($result->conflicted);
        $this->assertSame($newDir, $storage->getPath());
        $this->assertCount(1, $result->items);
        $this->assertSame(ScanItemType::NeedsManualEntry, $result->items[0]->type);
        $this->assertCount(1, $this->entityManager->getRepository(Storage::class)->findAll());
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

        $candidate = new SearchByPluginCandidate('animedb-test', 'Trigun', '');
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

    /**
     * End-to-end path for issue #233: SearchByPluginChain's contract candidate already carries
     * an externalId, so ScanStorageService's plugin branch bulk-fills the new Anime from that
     * plugin's FillerInterface (picking the subtype from PluginAnimeData::$type and caching the
     * externalId immediately) instead of the title-only placeholder — and does so without a
     * second find() call, since the externalId is already known.
     */
    public function testNewFileWithPluginCandidateCarryingExternalIdBulkFillsFromThatPluginWithoutASecondSearch(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Bleach.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $candidate = new SearchByPluginCandidate((string) $pluginId, 'Bleach: Memories of Nobody', '104');
        $service = $this->newService(
            $this->pluginChainReturning($candidate),
            $this->newBulkFillerService([(string) $pluginId => $filler]),
        );

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $anime = $item->anime;
        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('Bleach: Memories of Nobody', $anime->getTitle());
        $this->assertSame(91, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    /**
     * A plugin's find()/findById() throwing must not abort the whole storage scan (issue #233)
     * — the entry falls back to the same title-only placeholder used when no filler is
     * registered at all.
     */
    public function testNewFileWithPluginCandidateWhosePluginThrowsFallsBackToTitleOnlyPlaceholder(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $pluginId = new PluginId('animedb-shikimori');
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willThrowException(new \RuntimeException('external source unreachable'));

        $candidate = new SearchByPluginCandidate((string) $pluginId, 'Trigun', '104');

        // The exception is caught (and logged, see BulkFillerServiceTest) inside
        // BulkFillerService itself, so it never reaches ScanStorageService's own catch — this
        // test only needs to prove the fallback to a title-only placeholder still happens.
        $bulkFillerLogger = $this->createMock(LoggerInterface::class);
        $bulkFillerLogger->expects($this->once())->method('warning')->with(
            $this->stringContains('bulk-fill'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === (string) $pluginId
                && $context['exception'] instanceof \RuntimeException),
        );

        $service = $this->newService(
            $this->pluginChainReturning($candidate),
            new BulkFillerService(
                new FillerRegistry([(string) $pluginId => $filler], new PluginsConfigStore('')),
                new PluginAnimeDataMerger(
                    new StudioRepository($this->entityManager),
                    $this->entityManager,
                    $this->createStub(PluginMediaDownloaderInterface::class),
                ),
                $this->entityManager,
                $bulkFillerLogger,
            ),
        );

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $this->assertInstanceOf(TvAnime::class, $item->anime);
        $this->assertSame('Trigun', $item->anime->getTitle());
    }

    public function testNewFileWithPluginCandidateCarryingAMalformedPluginIdFallsBackToTitleOnlyPlaceholderAndLogsIt(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        // Not a valid "vendor-name" slug (see PluginId::FORMAT) — simulates a misbehaving
        // plugin reporting its own id incorrectly.
        $candidate = new SearchByPluginCandidate('Not A Valid Plugin Id', 'Trigun', '104');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('bulk-fill'),
            $this->arrayHasKey('exception'),
        );

        $service = $this->newService(
            $this->pluginChainReturning($candidate),
            logger: $logger,
        );

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $this->assertInstanceOf(TvAnime::class, $item->anime);
        $this->assertSame('Trigun', $item->anime->getTitle());
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

        $candidate = new SearchByPluginCandidate('animedb-test', 'Trigun', '');
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

    public function testExactlyOneOrphanDisagreeingWithExactlyOnePluginMatchRequiresConfirmation(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        // Same orphan-lookup result as the "confirmed" case above, but the plugin actually
        // found a different title this time — that must not be treated as agreement.
        $candidate = new SearchByPluginCandidate('animedb-test', 'Trigun the Movie', '');
        $service = $this->newService($this->pluginChainReturning($candidate));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::NeedsConfirmation, $item->type);
        $this->assertCount(2, $item->candidates);
        $this->assertNull($orphan->getStorage());
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

        $candidate = new SearchByPluginCandidate('animedb-test', 'Trigun the Movie', '');
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

    public function testTwoDifferentOrphansWithPluginAgreeingWithOnlyOneStillYieldTwoCandidates(): void
    {
        $dir = $this->makeStorageDir();
        // Both orphans below are found as candidates for this same file: $first through its
        // 'Vash' synonym, $second through its 'Vash' title.
        $this->touchFile($dir.'/Vash.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $first = new TvAnime();
        $first->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $first->addName('Vash', AnimeNameType::Synonym);
        $second = new TvAnime();
        $second->setTitle('Vash')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        // The plugin's name only matches $first's title ('Trigun'), not $second's ('Vash') —
        // the two orphans must not collapse into each other, and the agreeing pair must not
        // spawn a third, separate candidate.
        $candidate = new SearchByPluginCandidate('animedb-test', 'Trigun', '');
        $service = $this->newService($this->pluginChainReturning($candidate));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::NeedsConfirmation, $item->type);
        $this->assertCount(2, $item->candidates);
        $this->assertSame($first, $item->candidates[0]->orphan);
        $this->assertSame($second, $item->candidates[1]->orphan);

        // Neither orphan should have been touched while awaiting confirmation.
        $this->assertNull($first->getStorage());
        $this->assertNull($second->getStorage());
    }

    public function testSinglePluginReturningAmbiguousCandidatesRequiresConfirmation(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        // A single plugin call to an external source (e.g. Shikimori) can itself come back
        // ambiguous — here the TV series and its movie spin-off both matched "Trigun".
        $candidates = [
            new SearchByPluginCandidate('animedb-test', 'Trigun', ''),
            new SearchByPluginCandidate('animedb-test', 'Trigun: Badlands Rumble', ''),
        ];
        $service = $this->newService($this->pluginChainReturningAll($candidates));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::NeedsConfirmation, $item->type);
        $this->assertCount(2, $item->candidates);
    }

    public function testPluginCandidatesAgreeingWithEachOtherCollapseIntoOne(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $candidates = [
            new SearchByPluginCandidate('animedb-test', 'Trigun', ''),
            new SearchByPluginCandidate('animedb-test', 'trigun', ''),
        ];
        $service = $this->newService($this->pluginChainReturningAll($candidates));

        $result = $service->scan($storage);

        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame(ScanItemType::AutoLinked, $item->type);
        $this->assertNotNull($item->anime);
        $this->assertSame('Trigun', $item->anime->getTitle());
    }

    public function testLinkToChosenCandidateWithOrphanUpdatesTheExistingAnime(): void
    {
        $storage = new Storage('Main folder', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $anime = $this->newService()->linkToChosenCandidate($storage, 'Trigun.mkv', ScanCandidate::fromOrphan($orphan));
        $this->entityManager->flush();

        $this->assertSame($orphan, $anime);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $orphanId);
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    public function testLinkToChosenCandidateWithPluginCandidateCreatesANewAnime(): void
    {
        $storage = new Storage('Main folder', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $candidate = ScanCandidate::fromPlugin(new SearchByPluginCandidate('animedb-test', 'Trigun', ''));

        $anime = $this->newService()->linkToChosenCandidate($storage, 'Trigun.mkv', $candidate);
        $this->entityManager->flush();

        $this->assertSame('Trigun', $anime->getTitle());
        $this->assertSame($storage->id, $anime->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $anime->getStoragePath());

        $animeId = $anime->id;
        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $animeId);
        $this->assertSame('Trigun', $reloaded->getTitle());
    }

    public function testLinkToChosenCandidateRejectsAStoragePathAlreadyLinkedToAnotherAnime(): void
    {
        $storage = new Storage('Main folder', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $existing->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        // A second, different candidate loses the race for the same storage_path.
        $challenger = ScanCandidate::fromPlugin(new SearchByPluginCandidate('animedb-test', 'Trigun the Movie', ''));

        $this->expectException(StoragePathConflictException::class);
        $this->newService()->linkToChosenCandidate($storage, 'Trigun.mkv', $challenger);
    }

    public function testLinkToChosenCandidateRejectsAnOrphanConfirmedByOneRequestForAPathAnotherRequestAlreadyClaimed(): void
    {
        $storage = new Storage('Main folder', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $existing->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($existing);

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun the Other One')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $this->expectException(StoragePathConflictException::class);
        $this->newService()->linkToChosenCandidate($storage, 'Trigun.mkv', ScanCandidate::fromOrphan($orphan));
    }

    public function testLinkToChosenCandidateRejectsAnOrphanAlreadyLinkedToADifferentStoragePath(): void
    {
        $storage = new Storage('Main folder', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        // Already linked by another request between scan.done and this user's click.
        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $orphan->setStorage($storage)->setStoragePath('Trigun (2026).mkv');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $this->expectException(StoragePathConflictException::class);
        $this->newService()->linkToChosenCandidate($storage, 'Trigun.mkv', ScanCandidate::fromOrphan($orphan));
    }

    public function testLinkToChosenCandidateReconfirmingTheSameOrphanAndPathIsANoOp(): void
    {
        $storage = new Storage('Main folder', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $orphan->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $anime = $this->newService()->linkToChosenCandidate($storage, 'Trigun.mkv', ScanCandidate::fromOrphan($orphan));

        $this->assertSame($orphan, $anime);
        $this->assertSame('Trigun.mkv', $anime->getStoragePath());
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
