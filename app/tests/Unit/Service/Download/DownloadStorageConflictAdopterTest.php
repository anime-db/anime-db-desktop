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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadActionOutcome;
use App\Service\Download\DownloadActionService;
use App\Service\Download\DownloadAdoptionPathClassifier;
use App\Service\Download\DownloadAdoptionRefusedException;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadIncomingRelocator;
use App\Service\Download\DownloadOrphanAdopter;
use App\Service\Download\DownloadStorageConflictAdopter;
use App\Service\Download\DownloadStorageFilesystem;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DownloadStorageConflictAdopterTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:18080';
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private StorageMarkerService $markerService;
    private Storage $storage;
    private string $root;

    /** @var list<array{string, string, array<string, mixed>}> every request the mocked qBittorrent received */
    private array $requests = [];

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

        $this->downloads = new DownloadRepository($this->entityManager);
        $this->markerService = new StorageMarkerService($this->entityManager);

        $this->root = sys_get_temp_dir().'/animedb-conflict-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0o777, true);
        $this->storage = $this->persistStorage('AnimeDB', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir($path.'/'.$entry) ? $this->removeDirectory($path.'/'.$entry) : unlink($path.'/'.$entry);
            }
        }
        rmdir($path);
    }

    private function persistStorage(string $name, string $path): Storage
    {
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }
        $storage = new Storage($name, $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $this->markerService->reconcile($storage);

        return $storage;
    }

    private function persistAnime(?string $storagePath = null): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        if ($storagePath !== null) {
            $anime->setStorage($this->storage)->setStoragePath($storagePath);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** A row the poller failed with "storage_conflict": $anime is the one that lost the folder. */
    private function persistConflictRow(TvAnime $anime, ?Storage $target = null): Download
    {
        $download = new Download(self::HASH, $anime);
        $download->assignTargetStorage($target ?? $this->storage);
        $download->markFailed('storage_conflict');
        $this->downloads->save($download);

        return $download;
    }

    private function client(string $contentPath, bool $present = true): QbittorrentClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($contentPath, $present): MockResponse {
            $this->requests[] = [$method, $url, $options];
            if (str_contains($url, '/torrents/files')) {
                return new MockResponse(json_encode([['name' => 'Release/a.mkv'], ['name' => 'Release/b.mkv']], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
            }
            $torrents = $present ? [[
                'hash' => self::HASH,
                'infohash_v1' => self::HASH,
                'name' => 'Release',
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]] : [];

            return new MockResponse(json_encode($torrents, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return new QbittorrentClient($httpClient, self::BASE_URL);
    }

    private function adopter(QbittorrentClient $client): DownloadStorageConflictAdopter
    {
        $animes = new AnimeRepository($this->entityManager);

        return new DownloadStorageConflictAdopter(
            new DownloadOrphanAdopter(
                $client,
                new StorageRepository($this->entityManager),
                $this->downloads,
                $animes,
                $this->markerService,
                new ConflictTestFilesystem(),
                new DownloadAdoptionPathClassifier(new DownloadFolderJail()),
            ),
            $animes,
            new DownloadActionService($this->entityManager),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT anime_id, status, failure_reason, target_storage_id, move_attempts, version FROM downloads WHERE id = ?', [$id]);
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, string> $params
     */
    private function assertRefusedAndUntouched(string $key, Download $download, TvAnime $chosen, QbittorrentClient $client, array $params = []): void
    {
        $before = $this->row((int) $download->id);
        try {
            $this->adopter($client)->adopt($download, $chosen, $download->getVersion(), DownloadStatus::Failed);
            $this->fail('Expected a refusal with '.$key);
        } catch (DownloadAdoptionRefusedException $e) {
            $this->assertSame($key, $e->translationKey);
            foreach ($params as $name => $value) {
                $this->assertSame($value, $e->translationParams[$name] ?? null);
            }
        }
        $this->assertSame($before, $this->row((int) $download->id), 'A refusal must not change the row.');
    }

    public function testTheFolderOwnerIsAcceptedAndTheRowGoesToItAsPending(): void
    {
        $owner = $this->persistAnime('Release');
        $loser = $this->persistAnime();
        $download = $this->persistConflictRow($loser);
        $version = $download->getVersion();

        $outcome = $this->adopter($this->client($this->root.'\\Release'))->adopt($download, $owner, $version, DownloadStatus::Failed);

        $this->assertSame(DownloadActionOutcome::Success, $outcome);
        $row = $this->row((int) $download->id);
        $this->assertSame($owner->id, (int) $row['anime_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['failure_reason']);
        $this->assertSame($this->storage->id, (int) $row['target_storage_id']);
        $this->assertSame($version + 1, (int) $row['version']);

        // Read-only: torrents/info (no tag filter) and torrents/files; never a POST.
        $this->assertNotSame([], $this->requests);
        foreach ($this->requests as [$method, $url]) {
            $this->assertSame('GET', $method);
            $this->assertStringNotContainsString('tag=', $url);
        }
        $this->assertStringContainsString('/api/v2/torrents/info', $this->requests[0][1]);
    }

    public function testAStaleVersionIsAConflictAndTheRowStaysAsItWas(): void
    {
        $owner = $this->persistAnime('Release');
        $download = $this->persistConflictRow($this->persistAnime());
        $before = $this->row((int) $download->id);

        $outcome = $this->adopter($this->client($this->root.'\\Release'))->adopt($download, $owner, $download->getVersion() - 1, DownloadStatus::Failed);

        $this->assertSame(DownloadActionOutcome::Conflict, $outcome);
        $this->assertSame($before, $this->row((int) $download->id));
    }

    public function testAFolderOccupiedByAnotherCardIsRefusedWithItsNumber(): void
    {
        $occupant = $this->persistAnime('Release');
        $chosen = $this->persistAnime();
        $download = $this->persistConflictRow($this->persistAnime());

        $this->assertRefusedAndUntouched('download_adopt.error_folder_occupied', $download, $chosen, $this->client($this->root.'\\Release'), ['%id%' => (string) $occupant->id]);
    }

    public function testAnEntryWithAnotherFolderIsRefused(): void
    {
        // Nobody owns `Release` any more (the folder was freed), but the chosen entry has another folder.
        $chosen = $this->persistAnime('Different');
        $download = $this->persistConflictRow($this->persistAnime());

        $this->assertRefusedAndUntouched('download_adopt.error_anime_has_folder', $download, $chosen, $this->client($this->root.'\\Release'), ['%path%' => $this->root.'\\Different']);
    }

    public function testFilesInAnotherStorageThanTheRowsTargetAreRefusedWithTheStorageName(): void
    {
        $nested = $this->persistStorage('Nested', $this->root.'/Nested');
        $chosen = $this->persistAnime();
        // The row's target is the outer storage, but the files sit in the nested one.
        $download = $this->persistConflictRow($this->persistAnime(), $this->storage);

        $this->assertRefusedAndUntouched('download_adopt.error_storage_mismatch', $download, $chosen, $this->client($this->root.'\\Nested\\Release'), ['%storage%' => 'AnimeDB']);
        $this->assertNotSame($nested->id, $this->storage->id);
    }

    public function testATorrentMissingFromTheClientIsRefusedAndTheRowStaysAsItWas(): void
    {
        $chosen = $this->persistAnime();
        $download = $this->persistConflictRow($this->persistAnime());

        $this->assertRefusedAndUntouched('download_adopt.error_not_in_client', $download, $chosen, $this->client('', present: false));
    }

    public function testARowWithAnotherFailureReasonIsRefusedWithoutAskingTheClient(): void
    {
        $download = new Download(self::HASH, $this->persistAnime());
        $download->assignTargetStorage($this->storage);
        $download->markFailed('disk_space');
        $this->downloads->save($download);

        $outcome = $this->adopter($this->client($this->root.'\\Release'))->adopt($download, $this->persistAnime('Release'), $download->getVersion(), DownloadStatus::Failed);

        $this->assertSame(DownloadActionOutcome::Refused, $outcome);
        $this->assertSame([], $this->requests);
    }

    public function testFindFolderOwnerReturnsTheOwnerOrNull(): void
    {
        $owner = $this->persistAnime('Release');
        $download = $this->persistConflictRow($this->persistAnime());

        $this->assertSame($owner->id, $this->adopter($this->client($this->root.'\\Release'))->findFolderOwner($download)?->id);
        $this->assertNull($this->adopter($this->client($this->root.'\\Other'))->findFolderOwner($download));
        $this->assertNull($this->adopter($this->client('', present: false))->findFolderOwner($download));
    }

    public function testThePollerCompletesTheRelinkedRowOnTheChosenEntry(): void
    {
        $owner = $this->persistAnime('Release');
        $download = $this->persistConflictRow($this->persistAnime());
        $client = $this->client($this->root.'\\Release');
        $this->adopter($client)->adopt($download, $owner, $download->getVersion(), DownloadStatus::Failed);
        $this->entityManager->clear();

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))->method('dispatch')->willReturnCallback(function (object $event) use (&$dispatched): object {
            $dispatched[] = $event;

            return $event;
        });

        $jail = new DownloadFolderJail();
        $animes = new AnimeRepository($this->entityManager);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            new AnimeDownloadLinker($animes, $this->entityManager, $jail),
            $jail,
            new DownloadIncomingRelocator($client, $animes, $this->markerService, new ConflictTestFilesystem(), $this->entityManager, new NullLogger()),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertSame('completed', $this->row((int) $download->id)['status']);
        $this->assertSame($owner->id, (int) $this->row((int) $download->id)['anime_id']);
        $this->assertInstanceOf(AnimeFilesChangedEvent::class, $dispatched[0]);
        $this->assertInstanceOf(DownloadCompletedEvent::class, $dispatched[1]);
        $this->assertSame(self::HASH, $dispatched[1]->task->value);
        $this->assertSame($owner->id, $dispatched[1]->anime->value);
        foreach ($this->requests as [$method]) {
            $this->assertSame('GET', $method, 'Neither the relink nor the poll may send a command to the client.');
        }
    }
}

final class ConflictTestFilesystem implements DownloadStorageFilesystem
{
    public function pathExists(string $path): bool
    {
        return false;
    }

    public function isFile(string $path): bool
    {
        return false;
    }

    public function ensureDirectoryExists(string $path): void
    {
    }

    public function ensureHiddenDirectoryExists(string $path): void
    {
    }
}
