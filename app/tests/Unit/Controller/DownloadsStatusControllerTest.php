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

namespace App\Tests\Unit\Controller;

use App\Controller\DownloadsStatusController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadIncomingChecker;
use App\Service\Download\DownloadsOverviewBuilder;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class DownloadsStatusControllerTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:18080';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;

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

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->downloads = new DownloadRepository($this->entityManager);
    }

    private function persistDownload(string $infoHash): Download
    {
        $anime = new TvAnime();
        $anime->setTitle('Some anime')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $download = new Download($infoHash, $anime);
        $this->downloads->save($download);

        return $download;
    }

    private function createOverviewBuilder(): DownloadsOverviewBuilder
    {
        $translator = new Translator('ru');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3).'/translations/messages.ru.yaml', 'ru');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => \sprintf('/anime/%d', $params['id']),
        );

        return new DownloadsOverviewBuilder($this->downloads, new StorageMarkerService($this->entityManager), $translator, $urlGenerator, new DownloadIncomingChecker(new DownloadFolderJail(), new StorageRepository($this->entityManager)));
    }

    public function testStatusReturns200WithQbittorrentDataWhenReachable(): void
    {
        $this->persistDownload(str_repeat('a', 40));

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([
            ['infohash_v1' => str_repeat('a', 40), 'progress' => 0.2, 'state' => 'downloading'],
            ['infohash_v1' => str_repeat('f', 40), 'progress' => 0.2, 'state' => 'downloading'],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $controller = new DownloadsStatusController(new QbittorrentClient($httpClient, self::BASE_URL), $this->createOverviewBuilder());
        $response = $controller->status();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['qbittorrentAvailable']);
        self::assertSame('Ждёт', $data['rows'][0]['statusText']);
        self::assertFalse($data['rows'][0]['canDeleteFiles']);
        self::assertFalse($data['orphans'][0]['canDeleteFiles']);
    }

    public function testStatusReturns200WithUnavailableFlagWhenQbittorrentThrows(): void
    {
        $this->persistDownload(str_repeat('b', 40));

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused']));

        $controller = new DownloadsStatusController(new QbittorrentClient($httpClient, self::BASE_URL), $this->createOverviewBuilder());
        $response = $controller->status();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertFalse($data['qbittorrentAvailable']);
        self::assertSame([], $data['orphans']);
    }

    /**
     * Acceptance (issue #854): regardless of how many `downloads` rows exist, the endpoint must
     * make exactly one `torrents/info` request per call — not one per pending row.
     */
    public function testStatusMakesExactlyOneTorrentsInfoRequestRegardlessOfRowCount(): void
    {
        $this->persistDownload(str_repeat('c', 40));
        $this->persistDownload(str_repeat('d', 40));
        $this->persistDownload(str_repeat('e', 40));

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]));

        $controller = new DownloadsStatusController(new QbittorrentClient($httpClient, self::BASE_URL), $this->createOverviewBuilder());
        $controller->status();

        self::assertSame(1, $httpClient->getRequestsCount());
    }
}
