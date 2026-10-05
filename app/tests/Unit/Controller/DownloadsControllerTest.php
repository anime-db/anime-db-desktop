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

use App\Controller\DownloadsController;
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
use Twig\Environment;

/**
 * Functional coverage for the "Downloads" page (issue #854): a MockHttpClient stands in for
 * qBittorrent's WebUI, real `downloads` rows are persisted against an in-memory SQLite database,
 * and the real translation catalog is loaded — only Twig::render() itself is a mock, capturing
 * what the controller actually hands the template, the same way StorageControllerTest/
 * SyncReviewControllerTest already assert on a real controller's view-model without rendering
 * real HTML (see those tests for why: the templates' own markup is covered separately by
 * Twig-rendering tests).
 */
final class DownloadsControllerTest extends TestCase
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

    private function persistAnime(string $title): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function persistDownload(string $infoHash, TvAnime $anime): Download
    {
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

    public function testIndexMergesDbRowsWithQbittorrentDataAndListsOrphanTorrents(): void
    {
        $pending = $this->persistAnime('Pending anime');
        $this->persistDownload(str_repeat('a', 40), $pending);

        $completed = $this->persistAnime('Completed anime');
        $completedDownload = $this->persistDownload(str_repeat('b', 40), $completed);
        $completedDownload->markCompleted();

        $failed = $this->persistAnime('Failed anime');
        $failedDownload = $this->persistDownload(str_repeat('c', 40), $failed);
        $failedDownload->markFailed();
        (new \ReflectionProperty(Download::class, 'failureReason'))->setValue($failedDownload, 'disk_space');
        $this->entityManager->flush();

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([
            ['infohash_v1' => str_repeat('a', 40), 'progress' => 0.5, 'state' => 'downloading'],
            // No torrent for 'b' (Completed) — this is the "seeding stopped" case.
            // No torrent for 'c' (Failed) either — but a failure_reason is already known, so it
            // still shows the reason text, not "missing from client" (see
            // DownloadsOverviewBuilderTest for the row where a Failed row has no reason either).
            ['infohash_v1' => str_repeat('c', 40), 'progress' => 1.0, 'state' => 'error'],
            ['infohash_v1' => str_repeat('z', 40), 'name' => 'Orphan torrent', 'progress' => 0.1, 'state' => 'downloading'],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        /** @var array<string, mixed> $captured */
        $captured = [];
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/index.html.twig', $this->callback(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new DownloadsController($client, $this->createOverviewBuilder(), $twig);
        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($captured['qbittorrentAvailable']);

        $byHash = [];
        foreach ($captured['rows'] as $row) {
            $byHash[$row['infoHash']] = $row;
        }

        self::assertSame('Ждёт', $byHash[str_repeat('a', 40)]['statusText']);
        self::assertSame('Раздача остановлена', $byHash[str_repeat('b', 40)]['statusText']);
        self::assertSame('Ошибка: не хватает места на диске.', $byHash[str_repeat('c', 40)]['statusText']);

        self::assertCount(1, $captured['orphans']);
        self::assertFalse($captured['orphans'][0]['hasCard']);
        self::assertSame('Orphan torrent', $captured['orphans'][0]['displayName']);
    }

    public function testIndexDegradesGracefullyWhenQbittorrentIsUnreachable(): void
    {
        $anime = $this->persistAnime('Some anime');
        $this->persistDownload(str_repeat('d', 40), $anime);

        // An 'error' MockResponse makes reading the response (getStatusCode()) throw a
        // TransportExceptionInterface, same as a real connection failure to the sidecar.
        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused']));
        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        /** @var array<string, mixed> $captured */
        $captured = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $params) use (&$captured): string {
            $captured = $params;

            return '<html></html>';
        });

        $controller = new DownloadsController($client, $this->createOverviewBuilder(), $twig);
        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($captured['qbittorrentAvailable']);
        self::assertCount(1, $captured['rows']);
        self::assertSame('Ждёт', $captured['rows'][0]['statusText']);
        self::assertNull($captured['rows'][0]['sizeText']);
        self::assertSame([], $captured['orphans']);
    }
}
