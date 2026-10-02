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
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadFolderPointer;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\FreeSpaceProvider;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Download\QbittorrentDownloadService;
use App\Service\Download\TorrentInfoHashResolver;
use App\Service\Exception\InsufficientDiskSpaceException;
use App\Service\Exception\InvalidTorrentFileException;
use App\Service\Qbittorrent\QbittorrentClient;
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
    private const string ROOT = 'C:\\Users\\bob\\Downloads';
    private const string MAGNET_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private string $configPath;

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

        $this->configPath = sys_get_temp_dir().'/anime-download-service-test-'.uniqid().'.json';
        file_put_contents($this->configPath, json_encode(['downloadsRoot' => self::ROOT]));
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock', ...$this->torrentFilePaths] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse|null $onRequest
     */
    private function makeService(?callable $onRequest = null, ?FreeSpaceProvider $freeSpaceProvider = null): QbittorrentDownloadService
    {
        $httpClient = new MockHttpClient($onRequest ?? static function (): never {
            throw new \LogicException('No HTTP request was expected in this test.');
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));

        return new QbittorrentDownloadService(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $this->entityManager,
            $jail,
            new TorrentInfoHashResolver(),
            // The real NativeFreeSpaceProvider reports "unknown" (free-open) for self::ROOT on
            // this Linux test runner, since that Windows-style path never actually exists here —
            // only tests about the free-space check itself need to override this.
            new FreeSpaceChecker($jail, $freeSpaceProvider ?? new NativeFreeSpaceProvider()),
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

    public function testEnqueueAddsMagnetWithAJailedSavePathAndReturnsInfoHashAsTaskId(): void
    {
        $anime = $this->persistAnime();
        $captured = null;

        $service = $this->makeService(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('Ok.');
        });

        $taskId = $service->enqueue(
            DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH),
            new AnimeId((int) $anime->id),
        );

        $this->assertSame(self::MAGNET_HASH, $taskId->value);
        if (!\is_array($captured) || !\is_string($captured['body'] ?? null)) {
            $this->fail('Expected the request body to be captured as a string.');
        }
        $this->assertStringContainsString('savepath='.rawurlencode('\\\\?\\'.self::ROOT.'\\'.self::MAGNET_HASH), $captured['body']);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime(self::MAGNET_HASH, (int) $anime->id));
    }

    public function testEnqueueIsIdempotentForTheSameInfoHashAndAnime(): void
    {
        $anime = $this->persistAnime();
        $requestCount = 0;

        $service = $this->makeService(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('Ok.');
        });

        $source = DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH);
        $first = $service->enqueue($source, new AnimeId((int) $anime->id));
        $second = $service->enqueue($source, new AnimeId((int) $anime->id));

        $this->assertSame($first->value, $second->value);
        $this->assertSame(1, $requestCount);
        $this->assertCount(1, $this->downloads->findByInfoHash(self::MAGNET_HASH));
    }

    public function testEnqueueForASecondAnimeWithTheSameInfoHashThrowsWithOccupyingAnimeId(): void
    {
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();
        $requestCount = 0;

        $service = $this->makeService(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('Ok.');
        });

        $source = DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH);
        $service->enqueue($source, new AnimeId((int) $animeOne->id));

        try {
            $service->enqueue($source, new AnimeId((int) $animeTwo->id));
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

    public function testEnqueueForAnotherAnimeSucceedsAfterUnlinkingTheOccupyingOne(): void
    {
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();

        $service = $this->makeService(static fn (): MockResponse => new MockResponse('Ok.'));

        $source = DownloadSource::magnet('magnet:?xt=urn:btih:'.self::MAGNET_HASH);
        $service->enqueue($source, new AnimeId((int) $animeOne->id));

        try {
            $service->enqueue($source, new AnimeId((int) $animeTwo->id));
            $this->fail('Expected DownloadAlreadyLinkedToAnotherAnimeException to be thrown.');
        } catch (DownloadAlreadyLinkedToAnotherAnimeException) {
        }

        $unlink = new CommandTester(new DownloadsUnlinkCommand($this->downloads, new DownloadFolderPointer(), $this->entityManager));
        $unlink->execute(['info-hash' => self::MAGNET_HASH, 'anime-id' => (string) $animeOne->id]);
        $this->assertSame(0, $unlink->getStatusCode());

        $service->enqueue($source, new AnimeId((int) $animeTwo->id));

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

    public function testEnqueueRejectsATorrentFileThatDoesNotFitFreeSpace(): void
    {
        $anime = $this->persistAnime();
        $torrentBytes = $this->torrentFileBytes(500_000_000, 'Too.Big.Release.mkv');
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(100_000_000);

        $service = $this->makeService(null, $freeSpaceProvider);

        try {
            $service->enqueue(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id));
            $this->fail('Expected InsufficientDiskSpaceException to be thrown.');
        } catch (InsufficientDiskSpaceException) {
            // expected — asserted below that nothing was submitted/persisted either.
        }

        $this->assertSame([], $this->downloads->findByInfoHash($infoHash));
    }

    public function testEnqueueAddsATorrentFileWhenItFitsFreeSpaceWithMargin(): void
    {
        $anime = $this->persistAnime();
        $torrentBytes = $this->torrentFileBytes(1_000, 'Small.Release.mkv');
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(500_000_000);

        $captured = null;
        $service = $this->makeService(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('Ok.');
        }, $freeSpaceProvider);

        $taskId = $service->enqueue(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id));

        $this->assertSame($infoHash, $taskId->value);
        $this->assertNotNull($captured);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime($infoHash, (int) $anime->id));
    }

    public function testEnqueueRejectsAV2OnlyTorrentFileBeforeTouchingTheDatabaseOrQbittorrent(): void
    {
        $anime = $this->persistAnime();
        $torrentBytes = $this->v2OnlyTorrentFileBytes('V2.Only.Release.mkv', 1_000);
        $torrentPath = $this->writeTorrentFile($torrentBytes);

        // makeService(null) throws on any HTTP request — the rejection must happen before
        // submitToQbittorrent() ever runs.
        $service = $this->makeService(null);

        try {
            $service->enqueue(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id));
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
     * "pieces" turns this test red while testEnqueueRejectsAV2OnlyTorrentFileBeforeTouchingTheDatabaseOrQbittorrent()
     * stays green, confirming this test is the one guarding that distinction.
     */
    public function testEnqueueAcceptsAHybridTorrentFileDespiteCarryingMetaVersion(): void
    {
        $anime = $this->persistAnime();
        $torrentBytes = $this->hybridTorrentFileBytes('Hybrid.Release.mkv', 1_000);
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(500_000_000);

        $captured = null;
        $service = $this->makeService(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('Ok.');
        }, $freeSpaceProvider);

        $taskId = $service->enqueue(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id));

        $this->assertSame($infoHash, $taskId->value);
        $this->assertNotNull($captured);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime($infoHash, (int) $anime->id));
    }

    public function testEnqueueAcceptsAV1TorrentFileWithTheFullInfoKeySet(): void
    {
        $anime = $this->persistAnime();
        $torrentBytes = $this->v1TorrentFileBytes('V1.Release.mkv', 1_000);
        $torrentPath = $this->writeTorrentFile($torrentBytes);
        $infoHash = (new TorrentInfoHashResolver())->fromTorrentFileContent($torrentBytes);

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(500_000_000);

        $service = $this->makeService(static fn (): MockResponse => new MockResponse('Ok.'), $freeSpaceProvider);

        $taskId = $service->enqueue(DownloadSource::torrentFile($torrentPath), new AnimeId((int) $anime->id));

        $this->assertSame($infoHash, $taskId->value);
        $this->assertNotNull($this->downloads->findByInfoHashAndAnime($infoHash, (int) $anime->id));
    }
}
