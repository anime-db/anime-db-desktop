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

namespace App\Tests\Unit\Service\Download;

use AnimeDb\PluginContracts\Download\DownloadSource;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\QbittorrentDownloadService;
use App\Service\Download\TorrentInfoHashResolver;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
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
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @param callable(string, string, array<string, mixed>): MockResponse|null $onRequest */
    private function makeService(?callable $onRequest = null): QbittorrentDownloadService
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
        );
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

    public function testEnqueueForASecondAnimeWithTheSameInfoHashOnlyAddsALinkRow(): void
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
        $service->enqueue($source, new AnimeId((int) $animeTwo->id));

        $this->assertSame(1, $requestCount, 'A season pack must only be submitted to qBittorrent once.');
        $this->assertCount(2, $this->downloads->findByInfoHash(self::MAGNET_HASH));
    }
}
