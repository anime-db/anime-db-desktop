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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use App\Controller\StorageScanConfirmController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Storage\FilenameCleaner;
use App\Service\Storage\OrphanAnimeMatcher;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\Search\SearchByPluginChain;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Exercises StorageScanConfirmController against a real ScanStorageService/EntityManager
 * (same in-memory SQLite setup as ScanStorageServiceTest) rather than a mock, because
 * ScanStorageService is final and PHPUnit cannot double final classes.
 */
final class StorageScanConfirmControllerTest extends TestCase
{
    private EntityManager $entityManager;

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
    }

    /** @param iterable<string, FillerInterface> $fillers */
    private function createController(
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        iterable $fillers = [],
        ?AnimeRepository $animeRepository = null,
    ): StorageScanConfirmController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        $animeRepository ??= new AnimeRepository($this->entityManager);
        $scanStorageService = new ScanStorageService(
            new StorageMarkerService($this->entityManager),
            new FilenameCleaner(),
            new OrphanAnimeMatcher($animeRepository),
            new SearchByPluginChain([], new PluginsConfigStore('')),
            $animeRepository,
            $this->entityManager,
            new BulkFillerService(
                new FillerRegistry($fillers, new PluginsConfigStore('')),
                new PluginAnimeDataMerger(
                    new StudioRepository($this->entityManager),
                    $this->entityManager,
                    $this->createStub(PluginMediaDownloaderInterface::class),
                    new NullLogger(),
                ),
                $this->entityManager,
                new NullLogger(),
                $this->createMock(MessageBusInterface::class),
                $animeRepository,
                new CachedFillerLookup(new ArrayAdapter()),
            ),
            new SyncTombstoneRepository($this->entityManager),
            new NullLogger(),
        );

        return new StorageScanConfirmController(
            $scanStorageService,
            $this->entityManager,
            $csrfTokenManager,
            $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function jsonRequest(mixed $payload): Request
    {
        return Request::create('/storage/42/scan/confirm', 'POST', [], [], [], [], json_encode($payload) ?: '');
    }

    private function persistStorage(): Storage
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    public function testConfirmWithAnimeIdLinksTheExistingOrphan(): void
    {
        $storage = $this->persistStorage();

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $controller = $this->createController();
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => $orphanId,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Trigun.mkv', $body['storage_path']);
        $this->assertSame(['id' => $orphanId, 'title' => 'Trigun'], $body['anime']);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $orphanId);
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    public function testConfirmDispatchesAnimeFilesChangedEventWithPathChangedReasonAfterFlush(): void
    {
        $storage = $this->persistStorage();

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id ?? throw new \LogicException('Orphan must have an id once flushed.');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (AnimeFilesChangedEvent $event) use ($orphanId): bool {
                $this->assertSame($orphanId, $event->anime->value);
                $this->assertSame(FilesChangeReason::PathChanged, $event->reason);

                return true;
            }));

        $controller = $this->createController(eventDispatcher: $eventDispatcher);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => $orphanId,
        ]));
    }

    public function testConfirmWithPluginIdAndNameCreatesANewAnimeFromThePluginCandidate(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'plugin_id' => 'animedb-test',
            'external_id' => '',
            'name' => 'Trigun',
        ]));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Trigun', $body['anime']['title']);
        $this->assertIsInt($body['anime']['id']);
        $this->assertFalse($body['filled_from_plugin']);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $body['anime']['id']);
        $this->assertSame('Trigun', $reloaded->getTitle());
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    /**
     * End-to-end for issue #832: a confirmed candidate that carries a real pluginId/externalId
     * is filled in with the plugin's own data and has that externalId remembered, exactly like
     * the storage scan's own auto-link path — not the title-only placeholder
     * CONFIRMED_PLUGIN_ID used to force on every confirmation.
     */
    public function testConfirmWithPluginIdAndExternalIdFillsInTheNewAnimeFromThePlugin(): void
    {
        $storage = $this->persistStorage();

        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', durationMinutes: 91);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'durationMinutes']);

        $controller = $this->createController(fillers: [(string) $pluginId => $filler]);
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Bleach.mkv',
            'plugin_id' => (string) $pluginId,
            'external_id' => '104',
            'name' => 'Bleach: Memories of Nobody',
        ]));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Bleach: Memories of Nobody', $body['anime']['title']);
        $this->assertTrue($body['filled_from_plugin']);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $body['anime']['id']);
        $this->assertSame(91, $reloaded->getDurationMinutes());
        $this->assertSame('104', $reloaded->getCachedExternalId($pluginId));
    }

    /**
     * When the plugin cannot be reached, the new Anime still gets created with its title and
     * the externalId is still preserved (so a later dedup / fill-in can find it) — only
     * `filled_from_plugin` tells the frontend the data never arrived (issue #832 point 3).
     */
    public function testConfirmWithUnreachablePluginStillSavesTheExternalIdAndReportsNotFilledFromPlugin(): void
    {
        $storage = $this->persistStorage();

        $pluginId = new PluginId('animedb-shikimori');
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willThrowException(new \RuntimeException('unreachable'));
        $filler->method('getFillableFields')->willReturn(['title']);

        $controller = $this->createController(fillers: [(string) $pluginId => $filler]);
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Bleach.mkv',
            'plugin_id' => (string) $pluginId,
            'external_id' => '104',
            'name' => 'Bleach',
        ]));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Bleach', $body['anime']['title']);
        $this->assertFalse($body['filled_from_plugin']);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $body['anime']['id']);
        $this->assertSame('104', $reloaded->getCachedExternalId($pluginId));
    }

    /**
     * Regression guard (issue #832): confirming a plugin candidate whose (pluginId, externalId)
     * already belongs to a catalog record linked to a *different* storage_path must not create
     * a duplicate — it reports a structured 409 "conflict" body instead.
     */
    public function testConfirmWithAnExternalIdAlreadyLinkedElsewhereReturnsAStructuredConflict(): void
    {
        $storage = $this->persistStorage();

        $pluginId = new PluginId('animedb-shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $existing->rememberExternalId($pluginId, '104');
        $existing->setStorage($storage)->setStoragePath('Bleach (2026).mkv');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id;

        $controller = $this->createController();
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Bleach.mkv',
            'plugin_id' => (string) $pluginId,
            'external_id' => '104',
            'name' => 'Bleach',
        ]));

        $this->assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame($existingId, $body['conflict']['anime']['id']);
        $this->assertSame('Bleach', $body['conflict']['anime']['title']);
        $this->assertSame('Bleach (2026).mkv', $body['conflict']['storage_path']);
    }

    /**
     * Regression guard (issue #839): two confirm requests racing for the same plugin candidate
     * (two tabs showing the same stale scan.done payload for two different files, or a double
     * click processed as two separate requests) — the second one's create attempt loses the
     * anime_external_id UNIQUE race. Before the fix, BulkFillerService resolved that race by
     * reading through a *closed* EntityManager (Doctrine's own reaction to the failed flush that
     * caught it), so this second request's own flush() in respond() below threw
     * EntityManagerClosed — a 500, not the structured 409 or success this test requires. Forces
     * AnimeRepository::resolve() to miss twice in a row (same technique as
     * BulkFillerServiceTest's and ScanStorageServiceTest's own race regression tests) so both
     * requests' create attempts actually race, instead of the second one seeing the first one's
     * already-committed row up front.
     */
    public function testConfirmSurvivesAConcurrentCreateRaceForTheSamePluginCandidateWithoutClosingTheEntityManager(): void
    {
        $storage = $this->persistStorage();

        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title']);

        $racyAnimeRepository = new class($this->entityManager) extends AnimeRepository {
            public int $calls = 0;

            public function resolve(PluginId $pluginId, string $externalId): ?Anime
            {
                ++$this->calls;

                return $this->calls <= 2 ? null : parent::resolve($pluginId, $externalId);
            }
        };

        $controller = $this->createController(fillers: [(string) $pluginId => $filler], animeRepository: $racyAnimeRepository);

        $first = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Bleach.mkv',
            'plugin_id' => (string) $pluginId,
            'external_id' => '104',
            'name' => 'Bleach',
        ]));

        $this->assertSame(200, $first->getStatusCode());
        $winnerId = json_decode((string) $first->getContent(), true)['anime']['id'];

        // The real anime_external_id UNIQUE constraint is what decides this race — $racyAnimeRepository
        // only forces both up-front resolve() checks to miss, same as the first request; the second
        // request's own create attempt is the one that actually collides.
        $second = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Bleach2.mkv',
            'plugin_id' => (string) $pluginId,
            'external_id' => '104',
            'name' => 'Bleach',
        ]));

        // Not a 500: the lost race surfaces as the same structured "already linked elsewhere"
        // conflict a non-racy duplicate confirm gets (see
        // testConfirmWithAnExternalIdAlreadyLinkedElsewhereReturnsAStructuredConflict above).
        $this->assertSame(409, $second->getStatusCode());
        $secondBody = json_decode((string) $second->getContent(), true);
        $this->assertSame($winnerId, $secondBody['conflict']['anime']['id']);
        $this->assertSame('Bleach.mkv', $secondBody['conflict']['storage_path']);

        $this->assertTrue($this->entityManager->isOpen());

        $this->entityManager->clear();
        $this->assertCount(1, $this->entityManager->getRepository(Anime::class)->findAll());
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $winnerId);
        $this->assertSame('Bleach.mkv', $reloaded->getStoragePath());
        $this->assertSame('104', $reloaded->getCachedExternalId($pluginId));
    }

    public function testConfirmRejectsInvalidCsrfToken(): void
    {
        $storage = $this->persistStorage();

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);

        $this->expectException(BadRequestHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'bad',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => 7,
        ]));
    }

    public function testConfirmRejectsMissingStoragePath(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();

        $this->expectException(BadRequestHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'anime_id' => 7,
        ]));
    }

    public function testConfirmRejectsWhenNeitherAnimeIdNorNameGiven(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();

        $this->expectException(BadRequestHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
        ]));
    }

    public function testConfirmRejectsASecondRequestConfirmingADifferentCandidateForTheSameStoragePath(): void
    {
        $storage = $this->persistStorage();
        $controller = $this->createController();

        // First tab confirms a plugin candidate for the file...
        $first = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'plugin_id' => 'animedb-test',
            'name' => 'Trigun',
        ]));
        $this->assertSame(200, $first->getStatusCode());

        // ...a second tab, still showing the stale scan.done result, confirms a different
        // candidate for the very same storage_path (issue #147) and must be rejected, not
        // silently steal the file from the Anime the first request just created.
        $this->expectException(ConflictHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'plugin_id' => 'animedb-test',
            'name' => 'Trigun the Movie',
        ]));
    }

    public function testConfirmRejectsAnOrphanAlreadyLinkedToADifferentStoragePathByAnotherRequest(): void
    {
        $storage = $this->persistStorage();

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        // Simulates another request already having linked this orphan elsewhere between the
        // frontend receiving scan.done and the user clicking confirm.
        $orphan->setStorage($storage)->setStoragePath('Trigun (2026).mkv');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $controller = $this->createController();

        $this->expectException(ConflictHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => $orphanId,
        ]));
    }

    public function testConfirmRejectsUnknownAnimeId(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => 999,
        ]));
    }
}
