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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\SyncReviewController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeSyncState;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\AnimeSyncStateRepository;
use App\Repository\DownloadRepository;
use App\Repository\PendingSyncPushRepository;
use App\Repository\StorageRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\AnimeDeleteFlash;
use App\Service\AnimeDeleteService;
use App\Service\AnimeTypeChangeService;
use App\Service\AnimeViewFactory;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadIncomingChecker;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SourceRemovalPlan;
use App\Service\Sync\SourceRemovalPlanner;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncReconciler;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SyncReviewControllerTest extends TestCase
{
    private function createController(
        ?SyncReviewItemRepository $syncReviewItemRepository = null,
        ?AnimeRepository $animeRepository = null,
        ?SyncConvergenceService $syncConvergenceService = null,
        ?DeletedFromSourceDetector $deletedFromSourceDetector = null,
        ?EntityManagerInterface $entityManager = null,
        ?AnimeDeleteService $animeDeleteService = null,
        ?DownloadRepository $downloadRepository = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
        ?AnimeTypeChangeService $typeChangeService = null,
    ): SyncReviewController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/settings/sync-review');
        }

        $entityManager ??= $this->createStub(EntityManagerInterface::class);

        return new SyncReviewController(
            new SyncReviewService($syncReviewItemRepository ?? $this->createStub(SyncReviewItemRepository::class)),
            $animeRepository ?? $this->createStub(AnimeRepository::class),
            // SyncConvergenceService is final and can't be doubled — a real instance is built
            // here for every test, unused unless a NeedsCorrection resolve() actually reaches it.
            $syncConvergenceService ?? $this->createRealSyncConvergenceService($entityManager),
            // DeletedFromSourceDetector is final too — same reasoning, unused unless a
            // DeletedFromSource/DeletionConflict resolve() actually reaches it.
            $deletedFromSourceDetector ?? $this->createRealDeletedFromSourceDetector($entityManager),
            $animeDeleteService ?? $this->createRealAnimeDeleteService($entityManager),
            new AnimeDeleteFlash($this->createStub(TranslatorInterface::class), $this->createStub(UrlGeneratorInterface::class)),
            new SourceRemovalPlanner(new SyncRegistry([], new PluginsConfigStore(''))),
            $downloadRepository ?? $this->createStub(DownloadRepository::class),
            $entityManager,
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
            // AnimeTypeChangeService is final — a real one over the same entity manager.
            $typeChangeService ?? $this->createRealAnimeTypeChangeService($entityManager),
            new AnimeViewFactory(new RequestStack()),
            new Translator('en'),
        );
    }

    private function createRealAnimeTypeChangeService(EntityManagerInterface $entityManager): AnimeTypeChangeService
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return new AnimeTypeChangeService(
            $entityManager,
            new SyncRegistry([], new PluginsConfigStore('')),
            new JobLockService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->createStub(ProcessLivenessChecker::class), new MockClock(), 30, 3),
            $bus,
        );
    }

    private function createRealAnimeDeleteService(EntityManagerInterface $entityManager): AnimeDeleteService
    {
        return new AnimeDeleteService(
            $entityManager,
            $this->createStub(DownloadRepository::class),
            new SyncRegistry([], new PluginsConfigStore('')),
            new JobLockService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->createStub(ProcessLivenessChecker::class), new MockClock(), 30, 3),
            $this->createStub(SyncTombstoneRepository::class),
            new SourceRemovalPlanner(new SyncRegistry([], new PluginsConfigStore(''))),
            $this->createStub(MessageBusInterface::class),
            new SyncReviewService($this->createStub(SyncReviewItemRepository::class)),
            new QbittorrentClient(new MockHttpClient(), 'http://qb.test'),
            new DownloadIncomingChecker(new DownloadFolderJail(), new StorageRepository($entityManager)),
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }

    private function createRealSyncConvergenceService(EntityManagerInterface $entityManager): SyncConvergenceService
    {
        return new SyncConvergenceService(
            new SyncReconciler(),
            new AnimeSyncStateRepository($entityManager),
            new PendingSyncPushRepository($entityManager),
            new SyncRegistry([], new PluginsConfigStore('')),
            new SyncReviewService($this->createStub(SyncReviewItemRepository::class)),
            new NullLogger(),
        );
    }

    private function createRealDeletedFromSourceDetector(EntityManagerInterface $entityManager): DeletedFromSourceDetector
    {
        return new DeletedFromSourceDetector(
            new SyncRegistry([], new PluginsConfigStore('')),
            new SyncReviewService($this->createStub(SyncReviewItemRepository::class)),
            new AnimeSyncStateRepository($entityManager),
        );
    }

    private function createInMemoryEntityManager(): EntityManager
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        // As in doctrine.yaml: the raw SQL of the repositories names tables like `sync_review_item`.
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }

    public function testIndexPassesUnresolvedItemsAndDuplicateClustersToTemplate(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 10);

        $anime1 = new TvAnime();
        $anime1->setTitle('First');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime1, 1);
        $anime2 = new TvAnime();
        $anime2->setTitle('Second');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime2, 2);

        $syncReviewItemRepository = $this->createStub(SyncReviewItemRepository::class);
        $syncReviewItemRepository->method('findAllUnresolvedOrderedByCreatedAt')->willReturn([$item]);

        $animeRepository = $this->createMock(AnimeRepository::class);
        $animeRepository->expects($this->once())->method('findByIds')->with([1, 2])->willReturn([1 => $anime1, 2 => $anime2]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/sync_review/index.html.twig', $this->callback(
                static fn (array $params): bool => [$item] === $params['items']
                    && [10 => [$anime1, $anime2]] === $params['duplicateClusters']
                    && $params['needsCorrectionDetails'] === [],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, animeRepository: $animeRepository, twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesDeletionDetailsToTemplate(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::DeletionConflict, [
            'anime_id' => 7,
            'deleted_from' => 'animedb-shikimori',
            'still_present_on' => ['animedb-mal'],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 20);

        $anime = new TvAnime();
        $anime->setTitle('Trigun');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, 7);

        $syncReviewItemRepository = $this->createStub(SyncReviewItemRepository::class);
        $syncReviewItemRepository->method('findAllUnresolvedOrderedByCreatedAt')->willReturn([$item]);

        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturnCallback(
            static fn (array $ids): array => $ids === [7] ? [7 => $anime] : [],
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/sync_review/index.html.twig', $this->callback(
                static fn (array $params): bool => [$item] === $params['items']
                    && [20 => []] === $params['duplicateClusters']
                    && [20 => ['anime' => $anime, 'deletedFrom' => 'animedb-shikimori', 'stillPresentOn' => ['animedb-mal'], 'hasStorage' => false, 'hasFinishedDownloads' => false, 'sourceRemoval' => new SourceRemovalPlan()]] == $params['deletionDetails']
                    && $params['needsCorrectionDetails'] === [],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, animeRepository: $animeRepository, twig: $twig);

        $this->assertSame(200, $controller->index()->getStatusCode());
    }

    public function testResolveMarksItemResolvedAndRedirectsToIndex(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->once())->method('save')->with($item);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('settings_sync_review_index')
            ->willReturn('/settings/sync-review');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, urlGenerator: $router);
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', ['_token' => 'token']);

        $response = $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/sync-review', $response->getTargetUrl());
    }

    public function testResolveRejectsInvalidCsrfToken(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->never())->method('save');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, csrfTokenManager: $csrf);
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->resolve($item, $request);
    }

    /**
     * Acceptance (issue #382): choosing a NeedsCorrection candidate applies it via
     * SyncConvergenceService::applyManualResolution() (real engine, no plugins registered so it
     * only touches local) before the item itself is marked resolved.
     */
    public function testResolveAppliesTheChosenNeedsCorrectionCandidateAndRedirects(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $item = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $anime->id,
            'candidates' => [
                ['participant_id' => 'local', 'status' => 'plan', 'watched_episodes' => null, 'updated_at' => null],
                ['participant_id' => 'animedb-shikimori', 'status' => 'watching', 'watched_episodes' => 5, 'updated_at' => null],
            ],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->once())->method('save')->with($item);

        $controller = $this->createController(
            syncReviewItemRepository: $syncReviewItemRepository,
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', [
            '_token' => 'token',
            'participant_id' => 'animedb-shikimori',
        ]);

        $response = $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
        $this->assertSame(5, $anime->getWatchedEpisodes());
    }

    /**
     * The NeedsCorrection resolve form posts via HTMX (issue #382) so a successful pick removes
     * the item from the list without a full page reload — the controller must respond with a
     * plain 200 rather than the redirect the non-HTMX forms of the other kinds still get.
     */
    public function testResolveReturnsAnEmptyResponseForAnHtmxRequest(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $item = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $anime->id,
            'candidates' => [
                ['participant_id' => 'local', 'status' => 'plan', 'watched_episodes' => null, 'updated_at' => null],
                ['participant_id' => 'animedb-shikimori', 'status' => 'watching', 'watched_episodes' => 5, 'updated_at' => null],
            ],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $syncReviewItemRepository = $this->createStub(SyncReviewItemRepository::class);

        $controller = $this->createController(
            syncReviewItemRepository: $syncReviewItemRepository,
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', [
            '_token' => 'token',
            'participant_id' => 'local',
        ]);
        $request->headers->set('HX-Request', 'true');

        $response = $controller->resolve($item, $request);

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    /**
     * Correctness regression (PR #385 review): a chosen candidate can violate a local invariant
     * (Completed while the anime is still Announced/Ongoing, see Anime::setWatchStatus()) — the
     * engine rejects it silently rather than throwing (leaves $anime unchanged, only flags
     * getWatchProgressRejectedAt()). The item must not be marked resolved over a pick that never
     * actually took effect, so the controller must surface this as an error instead.
     */
    public function testResolveDoesNotResolveTheItemWhenTheChosenCandidateIsRejected(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $item = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $anime->id,
            'candidates' => [
                ['participant_id' => 'local', 'status' => 'plan', 'watched_episodes' => null, 'updated_at' => null],
                // A freshly-created TvAnime has no dates, so its production status is Announced,
                // never Released — Completed is rejected by Anime::setWatchStatus().
                ['participant_id' => 'animedb-shikimori', 'status' => 'completed', 'watched_episodes' => 12, 'updated_at' => null],
            ],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->never())->method('save');

        $controller = $this->createController(
            syncReviewItemRepository: $syncReviewItemRepository,
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', [
            '_token' => 'token',
            'participant_id' => 'animedb-shikimori',
        ]);

        $this->expectException(BadRequestHttpException::class);

        try {
            $controller->resolve($item, $request);
        } finally {
            $this->assertFalse($item->isResolved());
            $this->assertSame(WatchStatus::Plan, $anime->getWatchStatus());
        }
    }

    public function testResolveRejectsAnUnknownParticipantForNeedsCorrection(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $item = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $anime->id,
            'candidates' => [
                ['participant_id' => 'animedb-shikimori', 'status' => 'watching', 'watched_episodes' => 5, 'updated_at' => null],
            ],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $controller = $this->createController(
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', [
            '_token' => 'token',
            'participant_id' => 'animedb-mal',
        ]);

        $this->expectException(BadRequestHttpException::class);
        $controller->resolve($item, $request);
    }

    /**
     * Acceptance criterion 1 (issue #864): resolving a DeletedFromSource item as "keep" removes
     * the AnimeSyncState snapshot row for (anime, deleted_from) — the source no longer lists the
     * title, so the row's own claim of confirmed list membership for it is now stale. Rows for
     * other participants, and the cached external id, are untouched.
     */
    public function testResolveDeletedFromSourceRemovesTheSnapshotRowForTheSourcePluginOnly(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId(new PluginId('animedb-shikimori'), '10');
        $entityManager->persist($anime);
        $entityManager->flush();

        $this->seedSyncState($entityManager, $anime, 'animedb-shikimori');
        $this->seedSyncState($entityManager, $anime, 'animedb-mal');
        $this->seedSyncState($entityManager, $anime, 'local');

        $item = new SyncReviewItem(SyncReviewItemKind::DeletedFromSource, [
            'anime_id' => $anime->id,
            'deleted_from' => 'animedb-shikimori',
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->once())->method('save')->with($item);

        $controller = $this->createController(
            syncReviewItemRepository: $syncReviewItemRepository,
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', ['_token' => 'token']);

        $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
        $this->assertNull($this->findSyncState($entityManager, $anime, 'animedb-shikimori'));
        $this->assertNotNull($this->findSyncState($entityManager, $anime, 'animedb-mal'));
        $this->assertNotNull($this->findSyncState($entityManager, $anime, 'local'));
        $this->assertSame('10', $anime->getCachedExternalId(new PluginId('animedb-shikimori')));
    }

    /** Acceptance criterion 2 (issue #864): same removal for DeletionConflict. */
    public function testResolveDeletionConflictRemovesTheSnapshotRowForTheSourcePluginOnly(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $this->seedSyncState($entityManager, $anime, 'animedb-shikimori');
        $this->seedSyncState($entityManager, $anime, 'animedb-mal');

        $item = new SyncReviewItem(SyncReviewItemKind::DeletionConflict, [
            'anime_id' => $anime->id,
            'deleted_from' => 'animedb-shikimori',
            'still_present_on' => ['animedb-mal'],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 6);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->once())->method('save')->with($item);

        $controller = $this->createController(
            syncReviewItemRepository: $syncReviewItemRepository,
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/6/resolve', 'POST', ['_token' => 'token']);

        $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
        $this->assertNull($this->findSyncState($entityManager, $anime, 'animedb-shikimori'));
        $this->assertNotNull($this->findSyncState($entityManager, $anime, 'animedb-mal'));
    }

    /** Acceptance criterion 3 (issue #864): no snapshot row to begin with — resolve() still succeeds. */
    public function testResolveDeletedFromSourceWithoutASnapshotRowDoesNotThrow(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        $item = new SyncReviewItem(SyncReviewItemKind::DeletedFromSource, [
            'anime_id' => $anime->id,
            'deleted_from' => 'animedb-shikimori',
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 7);

        $controller = $this->createController(
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/7/resolve', 'POST', ['_token' => 'token']);

        $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
    }

    /**
     * Acceptance criterion 4 (issue #864): the catalog record named by the item's payload has
     * already been removed by the time it is resolved — resolve() still succeeds without error.
     */
    public function testResolveDeletedFromSourceWhenAnimeWasRemovedFromCatalogDoesNotThrow(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $item = new SyncReviewItem(SyncReviewItemKind::DeletedFromSource, [
            'anime_id' => 9999,
            'deleted_from' => 'animedb-shikimori',
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 8);

        $controller = $this->createController(
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/8/resolve', 'POST', ['_token' => 'token']);

        $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
    }

    /**
     * Acceptance criterion 5 (issue #864): resolving a PotentialDuplicate item must not touch any
     * AnimeSyncState snapshot row — the new removal is scoped to DeletedFromSource/DeletionConflict
     * only.
     */
    public function testResolvePotentialDuplicateDoesNotRemoveAnySnapshotRow(): void
    {
        $entityManager = $this->createInMemoryEntityManager();

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();
        $this->seedSyncState($entityManager, $anime, 'animedb-shikimori');

        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [$anime->id]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 9);

        $controller = $this->createController(
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
        );
        $request = Request::create('/settings/sync-review/9/resolve', 'POST', ['_token' => 'token']);

        $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
        $this->assertNotNull($this->findSyncState($entityManager, $anime, 'animedb-shikimori'));
    }

    private function seedSyncState(EntityManagerInterface $entityManager, Anime $anime, string $participantId): void
    {
        $entityManager->persist(new AnimeSyncState($anime, $participantId, WatchStatus::Plan, null, new \DateTimeImmutable()));
        $entityManager->flush();
    }

    private function findSyncState(EntityManagerInterface $entityManager, Anime $anime, string $participantId): ?AnimeSyncState
    {
        return $entityManager->find(AnimeSyncState::class, ['anime' => $anime, 'participantId' => $participantId]);
    }

    /** @return array{0: SyncReviewController, 1: SyncReviewItem, 2: Anime, 3: EntityManager} */
    private function typeMismatchFixture(string $sourceType, bool $series = true, ?CsrfTokenManagerInterface $csrfTokenManager = null): array
    {
        $entityManager = $this->createInMemoryEntityManager();

        if ($series) {
            $anime = new TvAnime();
            $anime->setTitle('Trigun')->setDatePremiereAndEnd(new \DateTimeImmutable('1998-04-01'), new \DateTimeImmutable('1998-09-30'))->setWatchStatus(WatchStatus::Plan);
            $anime->setEpisodesCount(26);
        } else {
            $anime = new \App\Entity\MovieAnime();
            $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        }
        $entityManager->persist($anime);
        $entityManager->flush();
        $item = new SyncReviewItem(SyncReviewItemKind::TypeMismatch, ['anime_id' => $anime->id ?? 0, 'plugin_id' => 'animedb-shikimori', 'source_type' => $sourceType]);
        $entityManager->persist($item);
        $entityManager->flush();

        $controller = $this->createController(
            syncReviewItemRepository: new SyncReviewItemRepository($entityManager),
            animeRepository: new AnimeRepository($entityManager),
            entityManager: $entityManager,
            csrfTokenManager: $csrfTokenManager,
        );

        return [$controller, $item, $anime, $entityManager];
    }

    /** @param array<string, string> $fields */
    private function acceptRequest(SyncReviewItem $item, array $fields = []): Request
    {
        $request = Request::create('/settings/sync-review/'.$item->id.'/accept-type', 'POST', ['_token' => 'token'] + $fields);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function storedType(EntityManager $entityManager, Anime $anime): string
    {
        return (string) $entityManager->getConnection()->fetchOne('SELECT type FROM anime WHERE id = ?', [$anime->id]);
    }

    private function isStoredResolved(EntityManager $entityManager, SyncReviewItem $item): bool
    {
        return $entityManager->getConnection()->fetchOne('SELECT resolved_at FROM sync_review_item WHERE id = ?', [$item->id]) !== null;
    }

    /** Issue #1002: inside the series types the source's type is taken at once. */
    public function testAcceptTypeWithinSeriesTypesChangesTheTypeAndResolvesTheItem(): void
    {
        [$controller, $item, $anime, $entityManager] = $this->typeMismatchFixture('ova');

        $controller->acceptType($item, $this->acceptRequest($item));

        $this->assertSame('ova', $this->storedType($entityManager, $anime));
        $this->assertTrue($this->isStoredResolved($entityManager, $item));
    }

    /** Issue #1002: series ⇄ movie goes through the dialog's confirmation. */
    public function testAcceptTypeThatDropsDataNeedsTheConfirmation(): void
    {
        [$controller, $item, $anime, $entityManager] = $this->typeMismatchFixture('movie');

        $controller->acceptType($item, $this->acceptRequest($item));

        $this->assertSame('tv', $this->storedType($entityManager, $anime));
        $this->assertFalse($this->isStoredResolved($entityManager, $item));

        $controller->acceptType($item, $this->acceptRequest($item, ['confirm_loss' => '1']));

        $this->assertSame('movie', $this->storedType($entityManager, $anime));
        $this->assertTrue($this->isStoredResolved($entityManager, $item));
    }

    public function testAcceptTypeRejectsInvalidCsrfTokenAndChangesNothing(): void
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        [$controller, $item, $anime, $entityManager] = $this->typeMismatchFixture('ova', csrfTokenManager: $csrf);
        $csrf->expects($this->once())
            ->method('isTokenValid')
            ->with($this->equalTo(new CsrfToken('settings_sync_review_accept_type_'.$item->id, 'token')))
            ->willReturn(false);

        try {
            $controller->acceptType($item, $this->acceptRequest($item));
            $this->fail('An invalid CSRF token must be rejected.');
        } catch (BadRequestHttpException) {
        }

        $this->assertSame('tv', $this->storedType($entityManager, $anime));
        $this->assertFalse($this->isStoredResolved($entityManager, $item));
    }

    public function testAcceptTypeIgnoresATypeSentInTheRequest(): void
    {
        [$controller, $item, $anime, $entityManager] = $this->typeMismatchFixture('ova');

        $controller->acceptType($item, $this->acceptRequest($item, ['type' => 'music']));

        $this->assertSame('ova', $this->storedType($entityManager, $anime));
    }

    public function testAcceptTypeIsRefusedForAnotherKind(): void
    {
        [$controller, , $anime, $entityManager] = $this->typeMismatchFixture('ova');
        $other = new SyncReviewItem(SyncReviewItemKind::DeletedFromSource, ['anime_id' => $anime->id, 'source_type' => 'ova']);
        $entityManager->persist($other);
        $entityManager->flush();

        $this->expectException(BadRequestHttpException::class);
        $controller->acceptType($other, $this->acceptRequest($other));
    }

    /** "Keep": the plain resolve leaves the type as it is. */
    public function testKeepResolvesATypeMismatchWithoutChangingTheType(): void
    {
        [$controller, $item, $anime, $entityManager] = $this->typeMismatchFixture('ova');

        $controller->resolve($item, Request::create('/settings/sync-review/'.$item->id.'/resolve', 'POST', ['_token' => 'token']));

        $this->assertSame('tv', $this->storedType($entityManager, $anime));
        $this->assertTrue($this->isStoredResolved($entityManager, $item));
    }
}
