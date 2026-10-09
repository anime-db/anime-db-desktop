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

namespace App\Tests\Unit\Service\Storage;

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Message\ScanStorageMessage;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\JobLock\JobLockService;
use App\Service\Storage\ManualLinkResult;
use App\Service\Storage\ManualLinkService;
use App\Service\Storage\ManualLinkStatus;
use App\Service\Storage\StorageMarkerService;
use App\Tests\Support\BuildsAnimeDeleteService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Steps 2-9 of {@see ManualLinkService::link()} against a real EntityManager and a real temp-dir filesystem (issue #997). */
final class ManualLinkServiceTest extends TestCase
{
    use BuildsAnimeDeleteService;

    private EntityManager $entityManager;
    private StorageMarkerService $markers;
    private JobLockService $jobLock;
    private EventDispatcher $dispatcher;

    /** @var list<AnimeFilesChangedEvent> */
    private array $events = [];

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
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->markers = new StorageMarkerService($this->entityManager);
        $this->jobLock = $this->newJobLockService();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(AnimeFilesChangedEvent::class, function (AnimeFilesChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeDir(string $suffix = ''): string
    {
        $dir = sys_get_temp_dir().'/manual-link-test-'.uniqid().$suffix;
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    private function newService(): ManualLinkService
    {
        return new ManualLinkService(
            new StorageRepository($this->entityManager),
            new AnimeRepository($this->entityManager),
            $this->markers,
            $this->jobLock,
            $this->entityManager,
            $this->dispatcher,
            new NullLogger(),
        );
    }

    private function newStorage(string $path, StorageType $type = StorageType::Folder, bool $withMarker = false): Storage
    {
        $storage = new Storage('Storage '.basename($path), $path, $type);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        if ($withMarker) {
            $this->markers->reconcile($storage);
        }

        return $storage;
    }

    private function newAnime(string $title = 'Trigun'): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function link(Anime $anime, string $path, ?int $relocate = null): ManualLinkResult
    {
        return $this->newService()->link($anime, $path, $relocate);
    }

    public function testLinksATopLevelFolderFoundByMarkerAndLiftsANestedPathToIt(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun/Season 1', recursive: true);
        $storage = $this->newStorage($root, withMarker: true);
        $anime = $this->newAnime();

        $result = $this->link($anime, $root.'/Trigun/Season 1');

        $this->assertSame(ManualLinkStatus::Linked, $result->status);
        $this->assertTrue($result->nested);
        $this->assertSame('Trigun', $result->entryName);
        $this->assertSame($storage, $anime->getStorage());
        $this->assertSame('Trigun', $anime->getStoragePath());
        $this->assertNotNull($anime->getFilesCheckedAt());
    }

    public function testDispatchesFilesChangedOnlyAfterASuccessfulLink(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $this->newStorage($root, withMarker: true);
        $anime = $this->newAnime();

        $this->link($anime, $root.'/Missing');
        $this->assertSame([], $this->events);

        $this->link($anime, $root.'/Trigun');
        $this->assertSame(
            [FilesChangeReason::PathChanged],
            array_map(static fn (AnimeFilesChangedEvent $event): FilesChangeReason => $event->reason, $this->events),
        );
    }

    public function testAMarkerWithAnUnknownIdIsSkippedAndTheClimbContinues(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Inner/Show', recursive: true);
        file_put_contents($root.'/Inner/desktop.ini', "[AnimeDB]\nid=9999\n");
        $storage = $this->newStorage($root, withMarker: true);
        $anime = $this->newAnime();

        $result = $this->link($anime, $root.'/Inner/Show');

        $this->assertSame(ManualLinkStatus::Linked, $result->status);
        $this->assertSame($storage, $anime->getStorage());
        $this->assertSame('Inner', $anime->getStoragePath());
    }

    public function testAMarkerOfAStorageWhosePathMovedAsksToRelocateAndChangesNothing(): void
    {
        $oldRoot = $this->makeDir();
        $newRoot = $this->makeDir();
        mkdir($newRoot.'/Trigun');
        $storage = $this->newStorage($oldRoot);
        file_put_contents($newRoot.'/desktop.ini', \sprintf("[AnimeDB]\nid=%d\n", $storage->id));
        $anime = $this->newAnime();

        $result = $this->link($anime, $newRoot.'/Trigun');

        $this->assertSame(ManualLinkStatus::RelocateRequired, $result->status);
        $this->assertSame($storage, $result->storage);
        $this->assertSame($newRoot, $result->path);
        $this->assertSame($oldRoot, $storage->getPath());
        $this->assertNull($anime->getStorage());
    }

    public function testConfirmedRelocationMovesTheStorageThenLinks(): void
    {
        $oldRoot = $this->makeDir();
        $newRoot = $this->makeDir();
        mkdir($newRoot.'/Trigun');
        $storage = $this->newStorage($oldRoot);
        file_put_contents($newRoot.'/desktop.ini', \sprintf("[AnimeDB]\nid=%d\n", $storage->id));
        $anime = $this->newAnime();

        $result = $this->link($anime, $newRoot.'/Trigun', $storage->id);

        $this->assertSame(ManualLinkStatus::Linked, $result->status);
        $this->assertSame($newRoot, $storage->getPath());
        $this->assertSame($storage, $anime->getStorage());
    }

    public function testRelocationConfirmedForAnotherStorageIsNotApplied(): void
    {
        $oldRoot = $this->makeDir();
        $newRoot = $this->makeDir();
        mkdir($newRoot.'/Trigun');
        $storage = $this->newStorage($oldRoot);
        file_put_contents($newRoot.'/desktop.ini', \sprintf("[AnimeDB]\nid=%d\n", $storage->id));

        $result = $this->link($this->newAnime(), $newRoot.'/Trigun', $storage->id + 1);

        $this->assertSame(ManualLinkStatus::RelocateRequired, $result->status);
        $this->assertSame($oldRoot, $storage->getPath());
    }

    public function testWithoutAMarkerTheStorageIsFoundByPathIgnoringCase(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $storage = $this->newStorage($root);
        $anime = $this->newAnime();

        $result = $this->link($anime, \dirname($root).'/'.strtoupper(basename($root)).'/Trigun');

        $this->assertSame(ManualLinkStatus::Linked, $result->status);
        $this->assertSame($storage, $anime->getStorage());
    }

    public function testTheStorageWithTheLongestRootWinsAmongNestedOnes(): void
    {
        $outerRoot = $this->makeDir();
        mkdir($outerRoot.'/Anime/Trigun', recursive: true);
        $this->newStorage($outerRoot);
        $inner = $this->newStorage($outerRoot.'/Anime');
        $anime = $this->newAnime();

        $result = $this->link($anime, $outerRoot.'/Anime/Trigun');

        $this->assertSame(ManualLinkStatus::Linked, $result->status);
        $this->assertSame($inner, $anime->getStorage());
        $this->assertSame('Trigun', $anime->getStoragePath());
    }

    public function testAPathOutsideEveryStoragePointsToTheParentOfTheSelectedItem(): void
    {
        $root = $this->makeDir();
        $other = $this->makeDir();
        touch($other.'/Movie.mkv');
        $this->newStorage($root);
        $anime = $this->newAnime();

        $result = $this->link($anime, $other.'/Movie.mkv');

        $this->assertSame(ManualLinkStatus::OutsideStorages, $result->status);
        $this->assertSame($other, $result->path);
        $this->assertNull($anime->getStorage());
    }

    public function testARelativePathIsRefused(): void
    {
        $this->assertSame(ManualLinkStatus::InvalidPath, $this->link($this->newAnime(), 'Trigun')->status);
    }

    public function testTheStorageRootItselfIsRefused(): void
    {
        $root = $this->makeDir();
        $this->newStorage($root);
        $anime = $this->newAnime();

        $result = $this->link($anime, $root.'/');

        $this->assertSame(ManualLinkStatus::StorageRoot, $result->status);
        $this->assertNull($anime->getStorage());
    }

    public function testARunningScanOfTheStorageBlocksTheLink(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $storage = $this->newStorage($root);
        $anime = $this->newAnime();
        $this->assertTrue($this->jobLock->acquire(ScanStorageMessage::jobKey((int) $storage->id)));

        $result = $this->link($anime, $root.'/Trigun');

        $this->assertSame(ManualLinkStatus::ScanRunning, $result->status);
        $this->assertNull($anime->getStorage());
    }

    public function testTheNameIsTakenFromTheRootListingNotFromTheSelectedString(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $this->newStorage($root);
        $anime = $this->newAnime();

        $result = $this->link($anime, $root.'/tRIGUN/Season 1');

        $this->assertSame(ManualLinkStatus::Linked, $result->status);
        $this->assertSame('Trigun', $anime->getStoragePath());
    }

    public function testNamesDifferingOnlyByCaseAreAmbiguousUnlessOneMatchesExactly(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        mkdir($root.'/TRIGUN');
        $this->newStorage($root);
        $anime = $this->newAnime();

        $this->assertSame(ManualLinkStatus::AmbiguousName, $this->link($anime, $root.'/tRiGuN')->status);
        $this->assertNull($anime->getStorage());

        $this->link($anime, $root.'/TRIGUN');
        $this->assertSame('TRIGUN', $anime->getStoragePath());
    }

    public function testAMissingItemIsRefused(): void
    {
        $root = $this->makeDir();
        $this->newStorage($root);

        $this->assertSame(ManualLinkStatus::EntryNotFound, $this->link($this->newAnime(), $root.'/Nothing')->status);
    }

    public function testHiddenItemsAndFilesThatAreNotVideoAreRefused(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/.anime-db/incoming/abc', recursive: true);
        touch($root.'/notes.txt');
        touch($root.'/Movie.mkv');
        $this->newStorage($root);
        $anime = $this->newAnime();

        $this->assertSame(ManualLinkStatus::EntryNotVisible, $this->link($anime, $root.'/.anime-db/incoming/abc')->status);
        $this->assertSame(ManualLinkStatus::EntryNotVisible, $this->link($anime, $root.'/notes.txt')->status);
        $this->assertNull($anime->getStorage());

        $this->assertSame(ManualLinkStatus::Linked, $this->link($anime, $root.'/Movie.mkv')->status);
        $this->assertSame('Movie.mkv', $anime->getStoragePath());
    }

    public function testAPairHeldByAnotherEntryIsRefusedIgnoringCaseAndATrailingSeparator(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $storage = $this->newStorage($root);
        $holder = $this->newAnime('Holder');
        $holder->setStorage($storage)->setStoragePath('trigun\\');
        $this->entityManager->flush();
        $anime = $this->newAnime();

        $result = $this->link($anime, $root.'/Trigun');

        $this->assertSame(ManualLinkStatus::Occupied, $result->status);
        $this->assertSame($holder, $result->occupiedBy);
        $this->assertNull($anime->getStorage());
        $this->assertSame([], $this->events);
    }

    public function testRelinkingAnEntryThatAlreadyHoldsThePairAndMovingItToAnotherItemIsAllowed(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        mkdir($root.'/Trigun Stampede');
        $storage = $this->newStorage($root);
        $anime = $this->newAnime();
        $anime->setStorage($storage)->setStoragePath('Trigun');
        $this->entityManager->flush();

        $this->assertSame(ManualLinkStatus::Linked, $this->link($anime, $root.'/Trigun')->status);
        $this->assertSame(ManualLinkStatus::Linked, $this->link($anime, $root.'/Trigun Stampede')->status);
        $this->assertSame('Trigun Stampede', $anime->getStoragePath());
    }

    public function testAnExternalReadOnlyStorageCanBeLinkedTo(): void
    {
        $root = $this->makeDir();
        mkdir($root.'/Trigun');
        $storage = $this->newStorage($root, StorageType::ExternalR);
        $anime = $this->newAnime();

        $this->assertSame(ManualLinkStatus::Linked, $this->link($anime, $root.'/Trigun')->status);
        $this->assertSame($storage, $anime->getStorage());
    }

    public function testUnlinkClearsTheStorageAndThePath(): void
    {
        $root = $this->makeDir();
        $storage = $this->newStorage($root);
        $anime = $this->newAnime();
        $anime->setStorage($storage)->setStoragePath('Trigun');

        $this->newService()->unlink($anime);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Anime::class, $anime->id);
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->getStorage());
        $this->assertNull($reloaded->getStoragePath());
    }
}
