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
use App\Service\Download\DownloadAdoptionPathClassifier;
use App\Service\Download\DownloadAdoptionRefusedException;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadIncomingRelocator;
use App\Service\Download\DownloadOrphanAdopter;
use App\Service\Download\DownloadStorageFilesystem;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DownloadOrphanAdopterTest extends TestCase
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

        $this->root = sys_get_temp_dir().'/animedb-adopt-test-'.bin2hex(random_bytes(8));
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

    private function persistStorage(string $name, string $path, bool $mark = true): Storage
    {
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }
        $storage = new Storage($name, $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        if ($mark) {
            $this->markerService->reconcile($storage);
        }

        return $storage;
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /**
     * @param list<string>|null $files torrents/files names; null means a multi-file torrent under `Release/`
     */
    private function client(string $contentPath, bool $present = true, float|int $progress = 1, ?array $files = null, string $clientHash = self::HASH): QbittorrentClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($contentPath, $present, $progress, $files, $clientHash): MockResponse {
            $this->requests[] = [$method, $url, $options];
            if (str_contains($url, '/torrents/files')) {
                $names = $files ?? ['Release/a.mkv', 'Release/b.mkv'];

                return new MockResponse(json_encode(array_map(static fn (string $name): array => ['name' => $name], $names), \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
            }
            $torrents = $present ? [[
                'hash' => $clientHash,
                'infohash_v1' => self::HASH,
                'name' => 'Release',
                'progress' => $progress,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]] : [];

            return new MockResponse(json_encode($torrents, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return new QbittorrentClient($httpClient, self::BASE_URL);
    }

    /**
     * @param list<string> $existingPaths
     */
    private function adopter(QbittorrentClient $client, array $existingPaths = [], ?DownloadRepository $downloads = null): DownloadOrphanAdopter
    {
        return new DownloadOrphanAdopter(
            $client,
            new StorageRepository($this->entityManager),
            $downloads ?? $this->downloads,
            new AnimeRepository($this->entityManager),
            $this->markerService,
            new AdoptTestFilesystem($existingPaths),
            new DownloadAdoptionPathClassifier(new DownloadFolderJail()),
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function assertRefused(string $key, callable $call, array $params = []): void
    {
        try {
            $call();
            $this->fail('Expected a refusal with '.$key);
        } catch (DownloadAdoptionRefusedException $e) {
            $this->assertSame($key, $e->translationKey);
            foreach ($params as $name => $value) {
                $this->assertSame($value, $e->translationParams[$name] ?? null);
            }
        }
        $this->assertSame([], $this->downloads->findByInfoHash(self::HASH), 'A refusal must not write a row.');
    }

    public function testAdoptWritesAPendingRowAndTouchesNothingInTheClient(): void
    {
        $anime = $this->persistAnime();
        $client = $this->client($this->root.'\\Release');

        $this->adopter($client)->adopt(self::HASH, (int) $anime->id);

        $rows = $this->downloads->findByInfoHash(self::HASH);
        $this->assertCount(1, $rows);
        $this->assertSame($anime->id, $rows[0]->getAnime()->id);
        $this->assertSame($this->storage->id, $rows[0]->getTargetStorage()?->id);
        $this->assertSame(DownloadStatus::Pending, $rows[0]->getStatus());
        $this->assertSame(0, $rows[0]->getMoveAttempts());

        // Read-only: torrents/info, then torrents/files to tell a single file from a folder.
        $this->assertCount(2, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        $this->assertSame('GET', $method);
        $this->assertStringContainsString('/api/v2/torrents/info', $url);
        $this->assertSame([], $options['query'] ?? []);
        $this->assertStringNotContainsString('tag=', $url);
    }

    public function testHybridTorrentFilesAreRequestedByTheClientHash(): void
    {
        $anime = $this->persistAnime();
        $v2 = str_repeat('b', 40);
        $client = $this->client($this->root.'\\Release', clientHash: $v2);

        $this->adopter($client)->adopt(self::HASH, (int) $anime->id);

        $this->assertCount(1, $this->downloads->findByInfoHash(self::HASH));
        $filesRequests = array_values(array_filter($this->requests, static fn (array $r): bool => str_contains($r[1], '/torrents/files')));
        $this->assertCount(1, $filesRequests);
        $this->assertSame($v2, $filesRequests[0][2]['query']['hash'] ?? null);
    }

    public function testTorrentMissingFromTheClientIsRefused(): void
    {
        $anime = $this->persistAnime();

        $this->assertRefused('download_adopt.error_not_in_client', fn () => $this->adopter($this->client('', present: false))->adopt(self::HASH, (int) $anime->id));
    }

    public function testClientFailureIsReportedAsUnavailable(): void
    {
        $anime = $this->persistAnime();
        $client = new QbittorrentClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])), self::BASE_URL);

        $this->assertRefused('download_new.error_client_unavailable', fn () => $this->adopter($client)->adopt(self::HASH, (int) $anime->id));
    }

    public function testUnknownAnimeIsRefused(): void
    {
        $this->assertRefused('download_adopt.error_anime_not_found', fn () => $this->adopter($this->client($this->root.'\\Release'))->adopt(self::HASH, 999));
    }

    public function testAlreadyLinkedTorrentIsRefusedWithTheOwnerNumber(): void
    {
        $owner = $this->persistAnime();
        $other = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $owner));

        try {
            $this->adopter($this->client($this->root.'\\Release'))->adopt(self::HASH, (int) $other->id);
            $this->fail('Expected a refusal.');
        } catch (DownloadAdoptionRefusedException $e) {
            $this->assertSame('download_adopt.error_already_linked', $e->translationKey);
            $this->assertSame(['%id%' => (string) $owner->id], $e->translationParams);
        }
        $this->assertCount(1, $this->downloads->findByInfoHash(self::HASH));
    }

    public function testFolderOccupiedByAnotherCardIsRefusedWithItsNumber(): void
    {
        $occupant = $this->persistAnime();
        $occupant->setStorage($this->storage)->setStoragePath('Release');
        $this->entityManager->flush();
        $anime = $this->persistAnime();

        $this->assertRefused(
            'download_adopt.error_folder_occupied',
            fn () => $this->adopter($this->client($this->root.'\\Release'))->adopt(self::HASH, (int) $anime->id),
            ['%id%' => (string) $occupant->id, '%path%' => $this->root.'\\Release'],
        );
    }

    public function testFolderOccupiedByTheSelectedAnimeIsAccepted(): void
    {
        $anime = $this->persistAnime();
        $anime->setStorage($this->storage)->setStoragePath('Release');
        $this->entityManager->flush();

        $this->adopter($this->client($this->root.'\\Release'))->adopt(self::HASH, (int) $anime->id);

        $this->assertCount(1, $this->downloads->findByInfoHash(self::HASH));
    }

    public function testAnimeWithAnotherFolderIsRefused(): void
    {
        $anime = $this->persistAnime();
        $anime->setStorage($this->storage)->setStoragePath('Different');
        $this->entityManager->flush();

        $this->assertRefused(
            'download_adopt.error_anime_has_folder',
            fn () => $this->adopter($this->client($this->root.'\\Release'))->adopt(self::HASH, (int) $anime->id),
            ['%path%' => $this->root.'\\Different'],
        );
    }

    public function testIncomingBranchIsRefusedWhenTheTargetExistsOnDisk(): void
    {
        $anime = $this->persistAnime();
        $content = $this->root.'\\.anime-db\\incoming\\'.self::HASH.'\\Release';

        $this->assertRefused(
            'download_adopt.error_incoming_target_exists',
            fn () => $this->adopter($this->client($content), [$this->root.'\\Release'])->adopt(self::HASH, (int) $anime->id),
            ['%path%' => $this->root.'\\Release'],
        );
    }

    public function testIncomingSingleFileChecksTheNameWithoutExtension(): void
    {
        $anime = $this->persistAnime();
        $content = $this->root.'\\.anime-db\\incoming\\'.self::HASH.'\\Movie.mkv';

        // Only the extension-less folder (where tryMove() would put it) counts as a conflict.
        $this->adopter($this->client($content, files: ['Movie.mkv']), [$this->root.'\\Movie.mkv'])->adopt(self::HASH, (int) $anime->id);
        $this->assertCount(1, $this->downloads->findByInfoHash(self::HASH));
    }

    public function testIncomingSingleFileIsRefusedWhenTheExtensionlessFolderExists(): void
    {
        $anime = $this->persistAnime();
        $content = $this->root.'\\.anime-db\\incoming\\'.self::HASH.'\\Movie.mkv';

        $this->assertRefused(
            'download_adopt.error_incoming_target_exists',
            fn () => $this->adopter($this->client($content, files: ['Movie.mkv']), [$this->root.'\\Movie'])->adopt(self::HASH, (int) $anime->id),
            ['%path%' => $this->root.'\\Movie'],
        );
    }

    public function testUnfinishedSingleFileInIncomingIsCheckedByTheExtensionlessName(): void
    {
        $anime = $this->persistAnime();
        $content = $this->root.'\\.anime-db\\incoming\\'.self::HASH.'\\Movie.mkv';

        // The file is not on disk yet (progress < 1): the single-file flag must come from the client.
        $this->assertRefused(
            'download_adopt.error_incoming_target_exists',
            fn () => $this->adopter($this->client($content, progress: 0.3, files: ['Movie.mkv']), [$this->root.'\\Movie'])->adopt(self::HASH, (int) $anime->id),
            ['%path%' => $this->root.'\\Movie'],
        );
    }

    public function testUnfinishedSingleFileInRootIsAcceptedAtDepthTwo(): void
    {
        $anime = $this->persistAnime();

        $this->adopter($this->client($this->root.'\\Release\\Movie.mkv', progress: 0.3, files: ['Movie.mkv']))->adopt(self::HASH, (int) $anime->id);

        $this->assertCount(1, $this->downloads->findByInfoHash(self::HASH));
    }

    public function testRootBranchDoesNotCheckTheDisk(): void
    {
        $anime = $this->persistAnime();

        $this->adopter($this->client($this->root.'\\Release'), [$this->root.'\\Release'])->adopt(self::HASH, (int) $anime->id);

        $this->assertCount(1, $this->downloads->findByInfoHash(self::HASH));
    }

    public function testNestedStorageWithAMismatchedMarkerDoesNotFallBackToTheOuterOne(): void
    {
        $anime = $this->persistAnime();
        // Marker never written for the nested storage, the outer one has a valid marker.
        $this->persistStorage('Nested', $this->root.'/Nested', mark: false);
        $content = $this->root.'\\Nested\\Movie.mkv';

        $this->assertRefused(
            'download_new.error_storage_unavailable',
            fn () => $this->adopter($this->client($content, files: ['Movie.mkv']))->adopt(self::HASH, (int) $anime->id),
        );
    }

    public function testNestedStorageWithAValidMarkerIsChosenOverTheOuterOne(): void
    {
        $anime = $this->persistAnime();
        $nested = $this->persistStorage('Nested', $this->root.'/Nested');

        $this->adopter($this->client($this->root.'\\Nested\\Release'))->adopt(self::HASH, (int) $anime->id);

        $this->assertSame($nested->id, $this->downloads->findByInfoHash(self::HASH)[0]->getTargetStorage()?->id);
    }

    public function testUniqueViolationOnSaveBecomesAnAlreadyLinkedRefusal(): void
    {
        $owner = $this->persistAnime();
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $owner));

        // The pre-check sees nothing (the race window), only the UNIQUE index on save() catches it.
        $racing = new class($this->entityManager) extends DownloadRepository {
            private int $lookups = 0;

            public function findByInfoHash(string $infoHash): array
            {
                return $this->lookups++ === 0 ? [] : parent::findByInfoHash($infoHash);
            }

            public function save(Download $download): void
            {
                throw new UniqueConstraintViolationException(new class('unique') extends \Exception implements DriverException {
                    public function getSQLState(): ?string
                    {
                        return null;
                    }
                }, null);
            }
        };

        try {
            $this->adopter($this->client($this->root.'\\Release'), downloads: $racing)->adopt(self::HASH, (int) $anime->id);
            $this->fail('Expected a refusal.');
        } catch (DownloadAdoptionRefusedException $e) {
            $this->assertSame('download_adopt.error_already_linked', $e->translationKey);
            $this->assertSame(['%id%' => (string) $owner->id], $e->translationParams);
        }
    }

    public function testRootBranchRowIsCompletedByThePoller(): void
    {
        $anime = $this->persistAnime();
        $client = $this->client($this->root.'\\Release');
        $this->adopter($client)->adopt(self::HASH, (int) $anime->id);

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))->method('dispatch')->willReturnCallback(function (object $event) use (&$dispatched): object {
            $dispatched[] = $event;

            return $event;
        });

        $jail = new DownloadFolderJail();
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail),
            $jail,
            new DownloadIncomingRelocator($client, new AnimeRepository($this->entityManager), $this->markerService, new AdoptTestFilesystem([]), $this->entityManager, new NullLogger()),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $stored = $this->downloads->findByInfoHash(self::HASH)[0];
        $this->assertSame(DownloadStatus::Completed, $stored->getStatus());
        $this->assertSame($this->root, $anime->getStorage()?->getPath());
        $this->assertSame('Release', $anime->getStoragePath());
        $this->assertInstanceOf(AnimeFilesChangedEvent::class, $dispatched[0]);
        $this->assertInstanceOf(DownloadCompletedEvent::class, $dispatched[1]);
        $this->assertSame(self::HASH, $dispatched[1]->task->value);
        $this->assertSame($anime->id, $dispatched[1]->anime->value);
        foreach ($this->requests as [$method]) {
            $this->assertSame('GET', $method, 'Neither the adoption nor the root-branch poll may send a command to the client.');
        }
    }
}

final class AdoptTestFilesystem implements DownloadStorageFilesystem
{
    /**
     * @param list<string> $existingPaths
     */
    public function __construct(private readonly array $existingPaths)
    {
    }

    public function pathExists(string $path): bool
    {
        return \in_array($path, $this->existingPaths, true);
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
