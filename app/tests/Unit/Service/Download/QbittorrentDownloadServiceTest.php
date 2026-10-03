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

use AnimeDb\PluginContracts\Download\DownloadAlreadyLinkedToAnotherAnimeException;
use AnimeDb\PluginContracts\Download\DownloadSource;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Command\DownloadsUnlinkCommand;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadFolderPointer;
use App\Service\Download\DownloadStorageFilesystem;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\FreeSpaceProvider;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Download\PresetDownloadsStorageProvider;
use App\Service\Download\QbittorrentDownloadService;
use App\Service\Download\TorrentInfoHashResolver;
use App\Service\Exception\DownloadNotConfirmedException;
use App\Service\Exception\DownloadStorageNotWritableException;
use App\Service\Exception\DownloadStorageUnavailableException;
use App\Service\Exception\InsufficientDiskSpaceException;
use App\Service\Exception\InvalidTorrentFileException;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QbittorrentDownloadServiceTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:18080';
    private const string MAGNET_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private StorageMarkerService $markerService;
    private string $storageDir;

    /** @var list<string> */
    private array $torrentFilePaths = [];

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

        $this->downloads = new DownloadRepository($this->entityManager);
        $this->markerService = new StorageMarkerService($this->entityManager);

        $this->storageDir = sys_get_temp_dir().'/anime-download-service-test-storage-'.uniqid();
        mkdir($this->storageDir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ($this->torrentFilePaths as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $marker = $this->storageDir.'/desktop.ini';
        if (is_file($marker)) {
            unlink($marker);
        }
        if (is_dir($this->storageDir)) {
            rmdir($this->storageDir);
        }
    }

    /**
     * Persists a writable Storage at a real temp directory and marks it (StorageMarkerService
     * reconcile()) the same way StorageNewController does for a storage created through the UI —
     * assertStorageAvailable() in QbittorrentDownloadService needs that real marker file to be
     * there, not just a database row, so this cannot be a bare in-memory fixture.
     */
    private function makeStorage(StorageType $type = StorageType::Folder): Storage
    {
        $storage = new Storage('AnimeDB', $this->storageDir, $type);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $this->markerService->reconcile($storage);

        return $storage;
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse|null $onRequest
     */
    private function makeService(
        ?callable $onRequest = null,
        ?FreeSpaceProvider $freeSpaceProvider = null,
        ?DownloadStorageFilesystem $storageFilesystem = null,
    ): QbittorrentDownloadService {
        $httpClient = new MockHttpClient($onRequest ?? static function (): never {
            throw new \LogicException('No HTTP request was expected in this test.');
        });

        $configPath = sys_get_temp_dir().'/anime-download-service-test-'.uniqid().'.json';
        $settings = new AppSettingsProvider(new AppConfigStore($configPath));

        return new QbittorrentDownloadService(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $this->entityManager,
            new DownloadFolderJail(),
            new TorrentInfoHashResolver(),
            // The real NativeFreeSpaceProvider reports "unknown" (free-open) for a real-but-empty
            // temp directory — only tests about the free-space check itself need to override this.
            new FreeSpaceChecker($freeSpaceProvider ?? new NativeFreeSpaceProvider()),
            new PresetDownloadsStorageProvider($this->entityManager, $settings, $this->markerService),
            $this->markerService,
            $storageFilesystem ?? new FakeDownloadStorageFilesystem(),
        );
    }

    private function bencodeString(string $value): string
    {
        return \strlen($value).':'.$value;
    }

    private function bencodeInt(int $value): string
    {
        return 'i'.$value.'e';
    }

    /**
     * @param array<string, string> $entries pre-bencoded values, keyed by their (already sorted) key
     */
    private function bencodeDict(array $entries): string
    {
        $body = '';
        foreach ($entries as $key => $value) {
            $body .= $this->bencodeString($key).$value;
        }

        return 'd'.$body.'e';
    }

    /**
     * @param list<string> $preBencodedItems
     */
    private function bencodeList(array $preBencodedItems): string
    {
        return 'l'.implode('', $preBencodedItems).'e';
    }

    /**
     * A minimal BEP52 "file tree" for a single-file torrent named $name: {name: {"": {length,
     * "pieces root"}}}. Only used to exercise the "info" dictionary's TOP-LEVEL keys (what
     * TorrentInfoHashResolver::isV2Only() inspects) — its own internal shape does not need to be
     * byte-exact to what qBittorrent itself produces.
     */
    private function bencodeFileTree(string $name, int $totalSize): string
    {
        return $this->bencodeDict([
            $name => $this->bencodeDict([
                '' => $this->bencodeDict([
                    'length' => $this->bencodeInt($totalSize),
                    'pieces root' => $this->bencodeString(str_repeat('B', 32)),
                ]),
            ]),
        ]);
    }

    private function bencodeFilesList(string $name, int $totalSize): string
    {
        return $this->bencodeList([$this->bencodeDict([
            'length' => $this->bencodeInt($totalSize),
            'path' => $this->bencodeList([$this->bencodeString($name)]),
        ])]);
    }

    /**
     * A BitTorrent v1 `.torrent` with the full "info" key set a real v1 torrent carries (issue
     * #843's "Детали"): files, name, piece length, pieces.
     */
    private function v1TorrentFileBytes(string $name, int $totalSize): string
    {
        $info = $this->bencodeDict([
            'files' => $this->bencodeFilesList($name, $totalSize),
            'name' => $this->bencodeString($name),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);

        return $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $info,
        ]);
    }

    /**
     * A hybrid (BitTorrent v1+v2) `.torrent` with the full "info" key set a real hybrid torrent
     * carries (issue #843's "Детали"): file tree, files, meta version, name, piece length,
     * pieces. Carries "meta version" like a v2-only torrent does, but keeps "pieces" for
     * v1-compatible clients — the fixture that would wrongly get rejected by a check that only
     * looked at "meta version" instead of "pieces".
     */
    private function hybridTorrentFileBytes(string $name, int $totalSize): string
    {
        $info = $this->bencodeDict([
            'file tree' => $this->bencodeFileTree($name, $totalSize),
            'files' => $this->bencodeFilesList($name, $totalSize),
            'meta version' => $this->bencodeInt(2),
            'name' => $this->bencodeString($name),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);

        return $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $info,
        ]);
    }

    /**
     * A BitTorrent v2-only `.torrent` with the full "info" key set a real v2-only torrent carries
     * (issue #843's "Детали"): file tree, meta version, name, piece length — no "pieces" and no
     * v1 info hash can be derived from it.
     */
    private function v2OnlyTorrentFileBytes(string $name, int $totalSize): string
    {
        $info = $this->bencodeDict([
            'file tree' => $this->bencodeFileTree($name, $totalSize),
            'meta version' => $this->bencodeInt(2),
            'name' => $this->bencodeString($name),
            'piece length' => $this->bencodeInt(16384),
        ]);

        return $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $info,
        ]);
    }

    private function writeTorrentFile(string $content): string
    {
        $path = sys_get_temp_dir().'/anime-download-service-test-'.uniqid().'.torrent';
        file_put_contents($path, $content);
        $this->torrentFilePaths[] = $path;

        return $path;
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
     * Every happy-path request this test suite sends needs both an "add" response (plain text,
     * matching qBittorrent's own `torrents/add` reply) and a "torrents/info" response reporting
     * the torrent back under $infoHash — confirmSubmitted() polls the latter before a `downloads`
     * row is ever written (issue #851's torrents/add-confirmation acceptance criterion).
     */
    private function happyPathResponder(string $infoHash): callable
    {
        return static function (string $method, string $url) use ($infoHash): MockResponse {
            if (str_contains($url, '/api/v2/torrents/info')) {
                return new MockResponse(
                    json_encode([['hash' => $infoHash, 'infohash_v1' => $infoHash]], \JSON_THROW_ON_ERROR),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }

            return new MockResponse('Ok.');
        };
    }

    public function testEnqueueToAddsMagnetWithAJailedSavePathAndReturnsInfoHashAsTaskId(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $captured = null;

        $service = $this->makeService(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            if (str_contains($url, '/api/v2/torrents/add')) {
                $captured = $options;

                return new MockResponse('Ok.');
            }

            return new MockResponse(
                json_encode([['hash' => self::MAGNET_HASH, 'infohash_v1' => self::MAGNET_HASH]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $taskId = $service->enqueueTo(
            DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
            new AnimeId((int) $anime->id),
            $storage,
        );

        $this->assertSame(self::MAGNET_HASH, $taskId->value);
        if (!\is_array($captured) || !\is_string($captured['body'] ?? null)) {
            $this->fail('Expected the request body to be captured as a string.');
        }
        $expectedSavePath = $storage->getPath().'\\.anime-db\\incoming\\'.self::MAGNET_HASH;
        $this->assertStringContainsString('savepath='.rawurlencode($expectedSavePath), $captured['body']);

        $stored = $this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame($storage->id, $stored->getTargetStorage()?->id);
    }

    public function testEnqueueToRejectsANonWritableStorageWithoutTouchingQbittorrentOrWritingARow(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage(StorageType::Video);

        // makeService(null) throws on any HTTP request — the rejection must happen before any
        // network call.
        $service = $this->makeService(null);

        try {
            $service->enqueueTo(
                DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
                new AnimeId((int) $anime->id),
                $storage,
            );
            $this->fail('Expected DownloadStorageNotWritableException to be thrown.');
        } catch (DownloadStorageNotWritableException $e) {
            $this->assertSame(StorageType::Video, $e->type);
        }

        $this->assertSame([], $this->downloads->findByInfoHash(self::MAGNET_HASH));
    }

    public function testEnqueueToRejectsAStorageWhosePathDoesNotExist(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();

        $storageFilesystem = new FakeDownloadStorageFilesystem(pathExists: false);
        $service = $this->makeService(null, storageFilesystem: $storageFilesystem);

        try {
            $service->enqueueTo(
                DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
                new AnimeId((int) $anime->id),
                $storage,
            );
            $this->fail('Expected DownloadStorageUnavailableException to be thrown.');
        } catch (DownloadStorageUnavailableException $e) {
            $this->assertSame($storage->id, $e->storageId);
        }

        $this->assertSame([], $this->downloads->findByInfoHash(self::MAGNET_HASH));
        $this->assertSame([], $storageFilesystem->hiddenDirectoryCalls, 'The incoming directory must not be created for an unavailable storage.');
    }

    public function testEnqueueToRejectsAStorageWhoseMarkerDoesNotMatch(): void
    {
        $anime = $this->persistAnime();
        // A real Storage row whose desktop.ini marker was never written for it (reconcile()
        // never ran) — readMarkerId() returns null, which cannot equal the storage's own id.
        $storage = new Storage('AnimeDB', $this->storageDir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $service = $this->makeService(null);

        $this->expectException(DownloadStorageUnavailableException::class);

        $service->enqueueTo(
            DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
            new AnimeId((int) $anime->id),
            $storage,
        );
    }

    public function testEnqueueToCreatesAndHidesTheIncomingDirectoryBeforeTorrentsAdd(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $storageFilesystem = new FakeDownloadStorageFilesystem();

        $addWasCalled = false;
        $service = $this->makeService(function (string $method, string $url) use (&$addWasCalled, $storageFilesystem): MockResponse {
            if (str_contains($url, '/api/v2/torrents/add')) {
                $addWasCalled = true;
                $this->assertNotSame([], $storageFilesystem->hiddenDirectoryCalls, 'The incoming directory must be created/hidden before torrents/add.');

                return new MockResponse('Ok.');
            }

            return new MockResponse(
                json_encode([['hash' => self::MAGNET_HASH, 'infohash_v1' => self::MAGNET_HASH]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, storageFilesystem: $storageFilesystem);

        $service->enqueueTo(
            DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
            new AnimeId((int) $anime->id),
            $storage,
        );

        $this->assertTrue($addWasCalled);
        $this->assertSame([$storage->getPath().'\\.anime-db'], $storageFilesystem->hiddenDirectoryCalls);
    }

    public function testEnqueueToConfirmsViaTorrentsInfoAndSucceedsOnTheThirdAttempt(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();

        $infoCalls = 0;
        $service = $this->makeService(function (string $method, string $url) use (&$infoCalls): MockResponse {
            if (str_contains($url, '/api/v2/torrents/add')) {
                return new MockResponse('Ok.');
            }

            ++$infoCalls;
            $torrents = $infoCalls >= 3
                ? [['hash' => self::MAGNET_HASH, 'infohash_v1' => self::MAGNET_HASH]]
                : [];

            return new MockResponse(
                json_encode($torrents, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $taskId = $service->enqueueTo(
            DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
            new AnimeId((int) $anime->id),
            $storage,
        );

        $this->assertSame(self::MAGNET_HASH, $taskId->value);
        $this->assertSame(3, $infoCalls);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $anime->id));
    }

    public function testEnqueueToFailsAfterFiveUnconfirmedAttemptsAndWritesNoRow(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();

        $infoCalls = 0;
        $service = $this->makeService(function (string $method, string $url) use (&$infoCalls): MockResponse {
            if (str_contains($url, '/api/v2/torrents/add')) {
                return new MockResponse('Ok.');
            }

            ++$infoCalls;

            return new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]);
        });

        try {
            $service->enqueueTo(
                DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
                new AnimeId((int) $anime->id),
                $storage,
            );
            $this->fail('Expected DownloadNotConfirmedException to be thrown.');
        } catch (DownloadNotConfirmedException $e) {
            $this->assertSame(self::MAGNET_HASH, $e->infoHash);
        }

        $this->assertSame(5, $infoCalls);
        $this->assertSame([], $this->downloads->findByInfoHash(self::MAGNET_HASH));
    }

    public function testEnqueueUsesThePresetStorageCreatedLazily(): void
    {
        $anime = $this->persistAnime();

        $configPath = sys_get_temp_dir().'/anime-download-service-preset-test-'.uniqid().'.json';
        $settings = new AppSettingsProvider(new AppConfigStore($configPath));
        $presetDir = sys_get_temp_dir().'/anime-download-service-preset-storage-'.uniqid();
        // PresetDownloadsStorageProvider's own reconcile() must find a real directory to write
        // desktop.ini into — the default path it resolves is "<home>/Downloads/AnimeDB".
        mkdir($presetDir.'/Downloads/AnimeDB', recursive: true);

        // PresetDownloadsStorageProvider resolves its path from %USERPROFILE%/%HOME% — point that
        // at a real, empty temp directory for the duration of this test so the marker it writes
        // lands on real disk, then restore it so other tests/processes are unaffected.
        $previousHome = getenv('HOME');
        $previousUserprofile = getenv('USERPROFILE');
        putenv('USERPROFILE='.$presetDir);
        putenv('HOME='.$presetDir);

        try {
            $httpClient = new MockHttpClient($this->happyPathResponder(self::MAGNET_HASH));
            $service = new QbittorrentDownloadService(
                new QbittorrentClient($httpClient, self::BASE_URL),
                $this->downloads,
                $this->entityManager,
                new DownloadFolderJail(),
                new TorrentInfoHashResolver(),
                new FreeSpaceChecker(new NativeFreeSpaceProvider()),
                new PresetDownloadsStorageProvider($this->entityManager, $settings, $this->markerService),
                $this->markerService,
                new FakeDownloadStorageFilesystem(),
            );

            $taskId = $service->enqueue(DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH), new AnimeId((int) $anime->id));

            $this->assertSame(self::MAGNET_HASH, $taskId->value);
            $stored = $this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $anime->id);
            $this->assertNotNull($stored);
            $presetId = $settings->getPresetDownloadsStorageId();
            $this->assertNotNull($presetId);
            $this->assertSame($presetId, $stored->getTargetStorage()?->id);
            $this->assertSame($presetDir.\DIRECTORY_SEPARATOR.'Downloads'.\DIRECTORY_SEPARATOR.'AnimeDB', $stored->getTargetStorage()->getPath());
        } finally {
            putenv($previousUserprofile === false ? 'USERPROFILE' : 'USERPROFILE='.$previousUserprofile);
            putenv($previousHome === false ? 'HOME' : 'HOME='.$previousHome);
            foreach ([$configPath, $configPath.'.tmp', $configPath.'.lock'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $this->removeRecursively($presetDir);
        }
    }

    private function removeRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeRecursively($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testEnqueueToIsIdempotentForTheSameInfoHashAndAnime(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $requestCount = 0;

        $service = $this->makeService(function (string $method, string $url) use (&$requestCount): MockResponse {
            if (str_contains($url, '/api/v2/torrents/add')) {
                ++$requestCount;

                return new MockResponse('Ok.');
            }

            return new MockResponse(
                json_encode([['hash' => self::MAGNET_HASH, 'infohash_v1' => self::MAGNET_HASH]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $source = DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH);
        $first = $service->enqueueTo($source, new AnimeId((int) $anime->id), $storage);
        $second = $service->enqueueTo($source, new AnimeId((int) $anime->id), $storage);

        $this->assertSame($first->value, $second->value);
        $this->assertSame(1, $requestCount);
        $this->assertCount(1, $this->downloads->findByInfoHash(self::MAGNET_HASH));
    }

    public function testEnqueueToForASecondAnimeWithTheSameInfoHashThrowsWithOccupyingAnimeId(): void
    {
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();
        $storage = $this->makeStorage();
        $requestCount = 0;

        $service = $this->makeService(function (string $method, string $url) use (&$requestCount): MockResponse {
            if (str_contains($url, '/api/v2/torrents/add')) {
                ++$requestCount;

                return new MockResponse('Ok.');
            }

            return new MockResponse(
                json_encode([['hash' => self::MAGNET_HASH, 'infohash_v1' => self::MAGNET_HASH]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $source = DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH);
        $service->enqueueTo($source, new AnimeId((int) $animeOne->id), $storage);

        try {
            $service->enqueueTo($source, new AnimeId((int) $animeTwo->id), $storage);
            $this->fail('Expected DownloadAlreadyLinkedToAnotherAnimeException to be thrown.');
        } catch (DownloadAlreadyLinkedToAnotherAnimeException $e) {
            $this->assertSame(self::MAGNET_HASH, $e->infoHash);
            $this->assertSame((int) $animeOne->id, $e->occupyingAnimeId->value);
            $this->assertStringContainsString('#'.$animeOne->id, $e->getMessage());
        }

        $this->assertSame(1, $requestCount, 'The torrent must not be submitted to qBittorrent again.');
        $this->assertCount(1, $this->downloads->findByInfoHash(self::MAGNET_HASH));
        $this->assertNull($this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $animeTwo->id));
    }

    public function testEnqueueToForAnotherAnimeSucceedsAfterUnlinkingTheOccupyingOne(): void
    {
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();
        $storage = $this->makeStorage();

        $service = $this->makeService($this->happyPathResponder(self::MAGNET_HASH));

        $source = DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH);
        $service->enqueueTo($source, new AnimeId((int) $animeOne->id), $storage);

        try {
            $service->enqueueTo($source, new AnimeId((int) $animeTwo->id), $storage);
            $this->fail('Expected DownloadAlreadyLinkedToAnotherAnimeException to be thrown.');
        } catch (DownloadAlreadyLinkedToAnotherAnimeException) {
        }

        $unlink = new CommandTester(new DownloadsUnlinkCommand($this->downloads, new DownloadFolderPointer(), $this->entityManager));
        $unlink->execute(['info-hash' => self::MAGNET_HASH, 'anime-id' => (string) $animeOne->id]);
        $this->assertSame(0, $unlink->getStatusCode());

        $service->enqueueTo($source, new AnimeId((int) $animeTwo->id), $storage);

        $this->assertNull($this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $animeOne->id));
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $animeTwo->id));
        $this->assertCount(1, $this->downloads->findByInfoHash(self::MAGNET_HASH));
    }

    private function torrentFileBytes(int $totalSize, string $name): string
    {
        $infoBytes = $this->bencodeDict([
            'length' => $this->bencodeInt($totalSize),
            'name' => $this->bencodeString($name),
            'piece length' => $this->bencodeInt(16384),
            'pieces' => $this->bencodeString(str_repeat('A', 20)),
        ]);

        return $this->bencodeDict([
            'announce' => $this->bencodeString('http://tracker.local/announce'),
            'info' => $infoBytes,
        ]);
    }

    public function testEnqueueToRejectsATorrentFileThatDoesNotFitFreeSpace(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $torrentBytes = $this->torrentFileBytes(500_000_000, 'Too.Big.Release.mkv');
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(100_000_000);

        $service = $this->makeService(null, $freeSpaceProvider);

        try {
            $service->enqueueTo(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id), $storage);
            $this->fail('Expected InsufficientDiskSpaceException to be thrown.');
        } catch (InsufficientDiskSpaceException) {
            // expected — asserted below that nothing was submitted/persisted either.
        }

        $this->assertSame([], $this->downloads->findByInfoHash($infoHash));
    }

    public function testEnqueueToAddsATorrentFileWhenItFitsFreeSpaceWithMargin(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $torrentBytes = $this->torrentFileBytes(1_000, 'Small.Release.mkv');
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(500_000_000);

        $service = $this->makeService($this->happyPathResponder($infoHash), $freeSpaceProvider);

        $taskId = $service->enqueueTo(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id), $storage);

        $this->assertSame($infoHash, $taskId->value);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime($infoHash, (int) $anime->id));
    }

    public function testEnqueueToRejectsAV2OnlyTorrentFileBeforeTouchingTheDatabaseOrQbittorrent(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $torrentBytes = $this->v2OnlyTorrentFileBytes('V2.Only.Release.mkv', 1_000);
        $torrentPath = $this->writeTorrentFile($torrentBytes);

        // makeService(null) throws on any HTTP request — the rejection must happen before
        // submitToQbittorrent() ever runs.
        $service = $this->makeService(null);

        try {
            $service->enqueueTo(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id), $storage);
            $this->fail('Expected InvalidTorrentFileException to be thrown.');
        } catch (InvalidTorrentFileException $e) {
            $this->assertStringContainsString('v2-only', $e->getMessage());
        }

        $this->assertSame([], $this->downloads->findDistinctPendingInfoHashes());
    }

    /**
     * A hybrid torrent carries "meta version" just like a v2-only one does (see
     * hybridTorrentFileBytes()'s docblock) — this must NOT be rejected, proving the check keys off
     * "pieces" rather than "meta version". Breaking isV2Only() to check "meta version" instead of
     * "pieces" turns this test red while testEnqueueToRejectsAV2OnlyTorrentFileBeforeTouchingTheDatabaseOrQbittorrent()
     * stays green, confirming this test is the one guarding that distinction.
     */
    public function testEnqueueToAcceptsAHybridTorrentFileDespiteCarryingMetaVersion(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $torrentBytes = $this->hybridTorrentFileBytes('Hybrid.Release.mkv', 1_000);
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(500_000_000);

        $service = $this->makeService($this->happyPathResponder($infoHash), $freeSpaceProvider);

        $taskId = $service->enqueueTo(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id), $storage);

        $this->assertSame($infoHash, $taskId->value);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime($infoHash, (int) $anime->id));
    }

    public function testEnqueueToAcceptsAV1TorrentFileWithTheFullInfoKeySet(): void
    {
        $anime = $this->persistAnime();
        $storage = $this->makeStorage();
        $torrentBytes = $this->v1TorrentFileBytes('V1.Release.mkv', 1_000);
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(500_000_000);

        $service = $this->makeService($this->happyPathResponder($infoHash), $freeSpaceProvider);

        $taskId = $service->enqueueTo(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id), $storage);

        $this->assertSame($infoHash, $taskId->value);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime($infoHash, (int) $anime->id));
    }
}

/**
 * Test double for the filesystem port QbittorrentDownloadService uses to check/create a storage's
 * incoming directory — a real implementation would need a real Windows volume (see
 * NativeDownloadStorageFilesystem's docblock), so tests fake it instead.
 */
final class FakeDownloadStorageFilesystem implements DownloadStorageFilesystem
{
    /** @var list<string> */
    public array $hiddenDirectoryCalls = [];

    public function __construct(private readonly bool $pathExists = true)
    {
    }

    public function pathExists(string $path): bool
    {
        return $this->pathExists;
    }

    public function ensureHiddenDirectoryExists(string $path): void
    {
        $this->hiddenDirectoryCalls[] = $path;
    }
}
