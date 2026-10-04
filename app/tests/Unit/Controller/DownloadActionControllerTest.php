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

use App\Controller\DownloadActionController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadActionService;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

/**
 * Functional coverage for the "Downloads" page's action buttons (issue #856): pause/resume, retry,
 * stop seeding, delete (with and without a torrent in the client) and removing an orphan torrent
 * from the client. Same shape as DownloadUnlinkControllerTest: a real controller against an
 * in-memory SQLite database and a MockHttpClient standing in for qBittorrent's WebUI, only
 * Twig::render() itself stubbed.
 */
final class DownloadActionControllerTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string BASE_URL = 'http://127.0.0.1:18080';

    private EntityManager $entityManager;
    private DownloadRepository $repository;

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
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new DownloadRepository($this->entityManager);
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Anime A')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function alwaysValidCsrfTokenManager(): CsrfTokenManagerInterface
    {
        $manager = $this->createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $token->getValue() === 'token',
        );

        return $manager;
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/downloads');

        return $urlGenerator;
    }

    private function overviewBuilder(): DownloadsOverviewBuilder
    {
        $translator = new Translator('ru');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3).'/translations/messages.ru.yaml', 'ru');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => \sprintf('/anime/%d', $params['id'] ?? 0),
        );

        return new DownloadsOverviewBuilder($this->repository, new StorageMarkerService($this->entityManager), $translator, $urlGenerator);
    }

    /**
     * Records every request the MockHttpClient saw as ['method', 'url', 'body'] — used to pin down
     * exactly which qBittorrent endpoint a controller action called, with what hashes, rather than
     * just asserting the controller's own HTTP status (see the three tests below this helper was
     * added for).
     *
     * @param list<array<string, mixed>>                              $torrents
     * @param list<array{method: string, url: string, body: ?string}> $requests
     */
    private function createRecordingController(array $torrents, array &$requests): DownloadActionController
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $torrents): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse(
                $method === 'GET' ? json_encode($torrents, \JSON_THROW_ON_ERROR) : 'Ok.',
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        return new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );
    }

    /** @param array<string, string> $fields */
    private function postRequest(array $fields = []): Request
    {
        return Request::create('/', 'POST', ['_token' => 'token'] + $fields);
    }

    public function testPauseCallsClientStopWithTheTorrentsOwnHash(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $requests = [];
        $controller = $this->createRecordingController(
            [['infohash_v1' => self::HASH, 'hash' => 'v2-hash', 'state' => 'downloading']],
            $requests,
        );
        $response = $controller->pause($download, $this->postRequest());

        $this->assertSame(302, $response->getStatusCode());
        $stopRequests = array_values(array_filter($requests, static fn (array $r): bool => str_ends_with($r['url'], '/torrents/stop')));
        $this->assertCount(1, $stopRequests, 'Exactly one POST to torrents/stop is expected.');
        $this->assertSame('hashes=v2-hash', $stopRequests[0]['body']);
    }

    public function testPauseIsRefusedForANonPendingRow(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed();
        $this->repository->save($download);

        $requests = [];
        $controller = $this->createRecordingController(
            [['infohash_v1' => self::HASH, 'hash' => 'v2-hash', 'state' => 'pausedDL']],
            $requests,
        );
        $response = $controller->pause($download, $this->postRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], array_filter($requests, static fn (array $r): bool => $r['method'] === 'POST'), 'A Failed row must never reach a POST call.');
    }

    public function testResumeCallsClientStartWithTheTorrentsOwnHash(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $requests = [];
        $controller = $this->createRecordingController(
            [['infohash_v1' => self::HASH, 'hash' => 'v2-hash', 'state' => 'pausedDL']],
            $requests,
        );
        $response = $controller->resume($download, $this->postRequest());

        $this->assertSame(302, $response->getStatusCode());
        $startRequests = array_values(array_filter($requests, static fn (array $r): bool => str_ends_with($r['url'], '/torrents/start')));
        $this->assertCount(1, $startRequests, 'Exactly one POST to torrents/start is expected.');
        $this->assertSame('hashes=v2-hash', $startRequests[0]['body']);
    }

    public function testResumeIsRefusedForANonPendingRow(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $this->repository->save($download);

        $requests = [];
        $controller = $this->createRecordingController(
            [['infohash_v1' => self::HASH, 'hash' => 'v2-hash', 'state' => 'pausedUP']],
            $requests,
        );
        $response = $controller->resume($download, $this->postRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], array_filter($requests, static fn (array $r): bool => $r['method'] === 'POST'), 'A Completed row must never reach a POST call.');
    }

    public function testRetrySucceedsMovesFailedToPendingAndStartsTheTorrent(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed('disk_space');
        $this->repository->save($download);

        $requests = [];
        $controller = $this->createRecordingController(
            [['infohash_v1' => self::HASH, 'hash' => 'v2-hash', 'state' => 'pausedDL']],
            $requests,
        );
        $response = $controller->retry($download, $this->postRequest());

        $this->assertSame(302, $response->getStatusCode());
        $startRequests = array_values(array_filter($requests, static fn (array $r): bool => str_ends_with($r['url'], '/torrents/start')));
        $this->assertCount(1, $startRequests, 'retry() must call torrents/start after the UPDATE succeeds.');
        $this->assertSame('hashes=v2-hash', $startRequests[0]['body']);
        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Pending, $reloaded?->getStatus());
    }

    /**
     * Pins issue #856's acceptance criterion: a stale version must not change the row's status and
     * must never reach the client at all.
     */
    public function testRetryWithAStaleVersionRendersConflictAndNeverCallsStart(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed('disk_space');
        $this->repository->save($download);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET version = version + 1 WHERE id = ?',
            [$download->id],
        );

        $methodsUsed = [];
        $httpClient = new MockHttpClient(function (string $method) use (&$methodsUsed): MockResponse {
            $methodsUsed[] = $method;

            return new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            $client,
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $controller->retry($download, $this->postRequest());

        // The conflict page re-render does its own GET /torrents/info, but start() — a POST —
        // must never be reached: asserting no POST happened pins that specifically.
        $this->assertNotContains('POST', $methodsUsed);

        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Failed, $reloaded?->getStatus());
    }

    public function testStopSeedingDeletesWithoutFilesAndKeepsTheRowAndItsStatus(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $this->repository->save($download);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/torrents/delete')) {
                $this->assertSame('hashes=v2-hash&deleteFiles=false', $options['body']);
            }

            return new MockResponse(
                $method === 'GET' ? json_encode([['infohash_v1' => self::HASH, 'hash' => 'v2-hash', 'state' => 'stoppedUP']], \JSON_THROW_ON_ERROR) : 'Ok.',
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });
        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            $client,
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $response = $controller->stopSeeding($download, $this->postRequest());

        $this->assertSame(302, $response->getStatusCode());
        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Completed, $reloaded?->getStatus());
    }

    public function testStopSeedingIsRefusedForANonCompletedRow(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $methodsUsed = [];
        $httpClient = new MockHttpClient(function (string $method) use (&$methodsUsed): MockResponse {
            $methodsUsed[] = $method;

            return new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $response = $controller->stopSeeding($download, $this->postRequest());

        $this->assertSame(200, $response->getStatusCode());
        // The refusal page re-render does its own GET /torrents/info, but delete() — a POST —
        // must never be reached.
        $this->assertNotContains('POST', $methodsUsed);
    }

    /**
     * Pins issue #856's "DB first" ordering for the delete action: the row is gone before this
     * test's MockHttpClient callback is ever invoked, and deleteFiles carries the checkbox value.
     */
    public function testDeletePendingRemovesTheRowBeforeCallingTheClientAndForwardsDeleteFiles(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $callOrder = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$callOrder): MockResponse {
            if (str_ends_with($url, '/torrents/delete')) {
                $callOrder[] = 'client-delete';
                $this->assertSame('hashes=v2-hash&deleteFiles=true', $options['body']);
            }

            return new MockResponse(
                $method === 'GET' ? json_encode([['infohash_v1' => self::HASH, 'hash' => 'v2-hash']], \JSON_THROW_ON_ERROR) : 'Ok.',
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });
        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            $client,
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $response = $controller->delete($download, $this->postRequest(['delete_files' => '1']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['client-delete'], $callOrder);
        $this->entityManager->clear();
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    /**
     * Pins issue #856's acceptance criterion: a 0-row conditional DELETE (the row became Completed
     * concurrently) must never reach the client at all, and the row is left exactly as the poller
     * left it.
     */
    public function testDeleteWithAZeroRowAffectedConditionalDeleteNeverCallsTheClient(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET status = ?, version = version + 1 WHERE id = ?',
            [DownloadStatus::Completed->value, $download->id],
        );

        $methodsUsed = [];
        $httpClient = new MockHttpClient(function (string $method) use (&$methodsUsed): MockResponse {
            $methodsUsed[] = $method;

            return new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $controller->delete($download, $this->postRequest());

        // findTorrent()'s pre-check and the conflict page's own re-render each do a GET
        // /torrents/info, but a torrents/delete POST must never be reached.
        $this->assertNotContains('POST', $methodsUsed);
        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Completed, $reloaded?->getStatus());
    }

    public function testDeleteForARowMissingFromTheClientOnlyDeletesTheRow(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed();
        $this->repository->save($download);

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]));
        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $controller->delete($download, $this->postRequest());

        $this->assertSame(1, $httpClient->getRequestsCount(), 'Only findTorrent()\'s GET; no delete call since there is no torrent.');
        $this->entityManager->clear();
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    public function testDeleteOrphanCallsClientDeleteWithoutTouchingTheDatabase(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/torrents/delete')) {
                $this->assertSame('hashes=v2-hash&deleteFiles=false', $options['body']);
            }

            return new MockResponse(
                $method === 'GET' ? json_encode([['infohash_v1' => self::HASH, 'hash' => 'v2-hash']], \JSON_THROW_ON_ERROR) : 'Ok.',
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });
        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            $client,
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $response = $controller->deleteOrphan(self::HASH, $this->postRequest());

        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * Pins issue #856's "actually an orphan" re-check: a card for this infoHash existing by the
     * time the click lands (a race with adding the torrent, or a stale tab) must refuse rather than
     * remove the torrent out from under a row DownloadCompletionPoller still has to progress.
     */
    public function testDeleteOrphanIsRefusedWhenARowAlreadyClaimsTheInfoHash(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $methodsUsed = [];
        $httpClient = new MockHttpClient(function (string $method) use (&$methodsUsed): MockResponse {
            $methodsUsed[] = $method;

            return new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->overviewBuilder(),
            $this->alwaysValidCsrfTokenManager(),
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $response = $controller->deleteOrphan(self::HASH, $this->postRequest());

        $this->assertSame(200, $response->getStatusCode());
        // The refusal page re-render does its own GET /torrents/info, but a torrents/delete POST
        // must never be reached.
        $this->assertNotContains('POST', $methodsUsed);
    }

    public function testDeleteRejectsInvalidCsrfTokenAndNeverTouchesTheRowOrClient(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']]));
        $controller = new DownloadActionController(
            new DownloadActionService($this->entityManager),
            $this->repository,
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->overviewBuilder(),
            $csrf,
            $this->urlGenerator(),
            $this->createStub(Environment::class),
        );

        $this->expectException(BadRequestHttpException::class);

        try {
            $controller->delete($download, Request::create('/', 'POST', ['_token' => 'bad']));
        } finally {
            $this->assertSame(0, $httpClient->getRequestsCount());
            $this->entityManager->clear();
            $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        }
    }
}
