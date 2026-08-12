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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Model\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\EventListener\DomainEventListener;
use App\Repository\AnimeRepository;
use App\Repository\AnimeSyncStateRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Search\AnimeSearchMatch;
use App\Service\Search\AnimeSearchResolver;
use App\Service\Sync\CrossVendorDuplicateDetector;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncReconciler;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Verifies the pull() core logic (issue #257): an already-known SyncItem updates its local
 * Anime's watchStatus in place, a genuinely new one is created and filled in through the sync
 * plugin's own filler capability (issue #257 review — no bare title-only stub), and running
 * the same pull() twice in a row never produces a duplicate row.
 */
final class PullSyncServiceTest extends TestCase
{
    private EntityManager $entityManager;
    private PullSyncService $service;
    private PluginId $pluginId;

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

        $this->service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager));
        $this->pluginId = new PluginId('animedb-shikimori');
    }

    /** @param array<string, SyncInterface> $otherSyncs active plugins other than $this->pluginId, for forward-propagation coverage */
    private function newService(EntityManager $entityManager, AnimeRepository $animeRepository, array $otherSyncs = []): PullSyncService
    {
        $bulkFillerService = new BulkFillerService(
            // Never consulted by fillNewFrom() — it works off the sync plugin instance directly.
            new FillerRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($entityManager),
                $entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $entityManager,
            new NullLogger(),
        );

        // A stub AnimeSearchResolver::tryResolveMatches() defaults to returning null (its
        // nullable-array return type), the same "search unavailable" signal CrossVendorDuplicateDetector
        // treats as skip-without-raising — dedup detection is exercised in its own test class, not here.
        $duplicateDetector = new CrossVendorDuplicateDetector(
            $this->createStub(AnimeSearchResolver::class),
            new SyncReviewService(new SyncReviewItemRepository($entityManager)),
        );

        // A single shared SyncRegistry, one active entry per $otherSyncs plus $this->pluginId
        // itself: production wiring shares one SyncRegistry between DeletedFromSourceDetector
        // and SyncConvergenceService the same way, and forward propagation needs $this->pluginId
        // resolvable through it too (SyncConvergenceService excludes it by id, not by omission).
        $settings = ['animedb-shikimori' => ['features' => ['sync' => true]]];
        foreach (array_keys($otherSyncs) as $id) {
            $settings[$id] = ['features' => ['sync' => true]];
        }
        $pluginsConfigPath = sys_get_temp_dir().'/anime-pull-sync-reg-'.uniqid().'.json';
        file_put_contents($pluginsConfigPath, json_encode($settings));
        $syncRegistry = new SyncRegistry($otherSyncs, new PluginsConfigStore($pluginsConfigPath));

        // Empty of *other* plugins by default, so a removed record without storage is flagged as
        // deleted_from_source (never a conflict) here; the conflict branch and storage protection
        // are covered in DeletedFromSourceDetectorTest.
        $deletionDetector = new DeletedFromSourceDetector(
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($entityManager)),
        );

        $convergenceService = new SyncConvergenceService(
            new SyncReconciler(),
            new AnimeSyncStateRepository($entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($entityManager)),
            new NullLogger(),
        );

        return new PullSyncService($entityManager, $animeRepository, $bulkFillerService, $duplicateDetector, $deletionDetector, $convergenceService, new NullLogger());
    }

    /**
     * SyncInterface and FillerInterface both extend ExternalIdResolutionInterface, so
     * createMockForIntersectionOfInterfaces() rejects them (it treats resolveExternalId(),
     * inherited by both, as a conflicting redeclaration) — a small concrete stub in place of a
     * generated mock instead. $pull and $data are set directly on the returned instance rather
     * than through a constructor, since PHPUnit's own mocks configure expectations the same way.
     *
     * @param iterable<SyncItem> $pull
     * @param string[]           $fillableFields
     */
    private function syncFillerStub(iterable $pull, ?PluginAnimeData $data, array $fillableFields = []): SyncInterface&FillerInterface
    {
        return new class($pull, $data, $fillableFields) implements SyncInterface, FillerInterface {
            /**
             * @param iterable<SyncItem> $pull
             * @param string[]           $fillableFields
             */
            public function __construct(
                private readonly iterable $pull,
                private readonly ?PluginAnimeData $data,
                private readonly array $fillableFields,
            ) {
            }

            public function resolveExternalId(array $urls): ?string
            {
                return null;
            }

            public function push(SyncItem $item): SyncItem
            {
                return $item;
            }

            public function pull(): iterable
            {
                return $this->pull;
            }

            public function find(string $name, ?callable $onHeartbeat = null): array
            {
                return [];
            }

            public function findById(string $externalId): ?PluginAnimeData
            {
                return $this->data;
            }

            public function getFillableFields(): array
            {
                return $this->fillableFields;
            }
        };
    }

    public function testUpdatesTheWatchStatusOfAnAlreadySyncedAnime(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop')]);

        $this->service->pull($this->pluginId, $sync);

        $this->assertCount(1, $this->allAnime());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    /**
     * Regression test for the pull->push echo loop (issue #352), updated for issue #371's
     * domain-driven trigger: a real DomainEventListener, the infra piece that drains
     * Anime::releaseEvents() on postPersist/postUpdate, is attached to this run's EntityManager
     * so it observes every flush the pull performs — mirroring how it is actually attached in
     * production. doPull() applies the incoming status through the plain Anime::setWatchStatus()
     * (not the manual-edit Anime::changeWatchStatusManually()), which never records a
     * WatchProgressChangedManuallyEvent in the first place, so there is nothing here for the
     * listener to release and dispatch — the echo is broken in the domain layer itself, no
     * $pushSuppressor-style runtime guard needed for this trigger any more.
     */
    public function testPullDoesNotDispatchPushSyncMessageForTheStatusChangeItAppliedItself(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');
        $this->entityManager->getEventManager()->addEventListener(
            [Events::postPersist, Events::postUpdate],
            new DomainEventListener($eventDispatcher),
        );

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop')]);

        $this->service->pull($this->pluginId, $sync);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testSkipsAnUnknownExternalIdWhenTheFillerCannotResolveIt(): void
    {
        $sync = $this->syncFillerStub([new SyncItem('42', SyncStatus::Watching, 'Trigun')], data: null);

        $this->service->pull($this->pluginId, $sync);

        $this->assertCount(0, $this->allAnime());
    }

    public function testCreatesAndFillsInANewAnimeThroughTheSyncPluginsOwnFillerCapability(): void
    {
        $data = new PluginAnimeData(
            title: 'Trigun',
            type: ContractsAnimeType::Tv,
            datePremiere: new \DateTimeImmutable('2 years ago'),
            dateEnd: new \DateTimeImmutable('1 year ago'),
        );

        $sync = $this->syncFillerStub(
            [new SyncItem('42', SyncStatus::Completed, 'Trigun')],
            data: $data,
            fillableFields: ['title', 'type', 'datePremiere', 'dateEnd'],
        );

        $this->service->pull($this->pluginId, $sync);

        $created = $this->allAnime();
        $this->assertCount(1, $created);
        $this->assertSame('Trigun', $created[0]->getTitle());
        $this->assertSame(WatchStatus::Completed, $created[0]->getWatchStatus());
        $this->assertSame('42', $created[0]->getCachedExternalId($this->pluginId));
    }

    /**
     * The up-front indexByExternalId() snapshot cannot see a concurrent create that lands
     * after it — the real guard is the anime_external_id UNIQUE(plugin_id, external_id)
     * constraint inside BulkFillerService::build() (issue #297). Simulated here by seeding
     * the "winning" Anime from inside the sync plugin's own pull() generator: PHP generators
     * run their body lazily, so this insert happens exactly between pull()'s
     * indexByExternalId() call and its loop reaching this item, the same window a real
     * concurrent process would race into.
     */
    public function testALostCreateRaceUpdatesTheWinnerInsteadOfFailingTheWholeBatch(): void
    {
        $pluginId = $this->pluginId;
        $entityManager = $this->entityManager;

        $pull = (function () use ($pluginId, $entityManager): \Generator {
            $winner = new TvAnime();
            $winner->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
            $winner->rememberExternalId($pluginId, '42');
            $entityManager->persist($winner);
            $entityManager->flush();

            yield new SyncItem('42', SyncStatus::Watching, 'Trigun');
        })();

        $sync = $this->syncFillerStub(
            $pull,
            data: new PluginAnimeData(title: 'Trigun', type: ContractsAnimeType::Tv),
            fillableFields: ['title', 'type'],
        );

        $this->service->pull($pluginId, $sync);

        $all = $this->allAnime();
        // No duplicate: BulkFillerService's own attempt lost the race, and its orphaned
        // Anime row was cleaned up rather than left dangling without an external id.
        $this->assertCount(1, $all);
        // The item's status is applied to the winner via the recovery path, not dropped.
        $this->assertSame(WatchStatus::Watching, $all[0]->getWatchStatus());
    }

    /**
     * The recovery EntityManager opened after a lost create race (see the class docblock's
     * "Create-conflict recovery" section) shares this run's original DBAL connection — and
     * therefore its Doctrine EventManager/listeners, per Doctrine\ORM\EntityManager's own
     * constructor (it falls back to $conn->getEventManager() when none is passed explicitly).
     * Updated for issue #371: doPull()'s Anime::setWatchStatus() call (through either
     * EntityManager) never records a domain event, so DomainEventListener — attached here
     * the same way it is in production — has nothing to release/dispatch for either flush.
     */
    public function testRecoveryEntityManagerFlushesDuringAPullAreAlsoSuppressed(): void
    {
        $pluginId = $this->pluginId;
        $entityManager = $this->entityManager;

        $pull = (function () use ($pluginId, $entityManager): \Generator {
            $winner = new TvAnime();
            $winner->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
            $winner->rememberExternalId($pluginId, '42');
            $entityManager->persist($winner);
            $entityManager->flush();

            yield new SyncItem('42', SyncStatus::Watching, 'Trigun');
        })();

        $sync = $this->syncFillerStub(
            $pull,
            data: new PluginAnimeData(title: 'Trigun', type: ContractsAnimeType::Tv),
            fillableFields: ['title', 'type'],
        );

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');
        $this->entityManager->getEventManager()->addEventListener(
            [Events::postPersist, Events::postUpdate],
            new DomainEventListener($eventDispatcher),
        );

        $this->service->pull($pluginId, $sync);

        $all = $this->allAnime();
        $this->assertSame(WatchStatus::Watching, $all[0]->getWatchStatus());
    }

    /**
     * Wiring check for the cross-vendor dedup heuristic (issue #268): a genuinely new Anime
     * created via fillNewFrom() is run through CrossVendorDuplicateDetector once flush() has
     * given it an id — case-by-case threshold/skip behavior belongs to
     * CrossVendorDuplicateDetectorTest, this only confirms pull() actually invokes it for a
     * newly created row (not for one resolved through $byExternalId) and that the resulting
     * SyncReviewItem lands in the same entity manager pull() itself used.
     */
    public function testANewlyCreatedAnimeIsRunThroughTheCrossVendorDuplicateDetector(): void
    {
        $existing = new TvAnime();
        $existing->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->id ?? throw new \LogicException('id must be set after flush');

        $resolver = $this->createMock(AnimeSearchResolver::class);
        $resolver->expects($this->once())
            ->method('tryResolveMatches')
            ->with('Trigun')
            ->willReturn([new AnimeSearchMatch($existingId, 0.95)]);

        $bulkFillerService = new BulkFillerService(
            new FillerRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            new NullLogger(),
        );
        $duplicateDetector = new CrossVendorDuplicateDetector(
            $resolver,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
        );
        $syncRegistry = new SyncRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-reg-'.uniqid().'.json'));
        $deletionDetector = new DeletedFromSourceDetector(
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
        );
        $convergenceService = new SyncConvergenceService(
            new SyncReconciler(),
            new AnimeSyncStateRepository($this->entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            new NullLogger(),
        );
        $service = new PullSyncService($this->entityManager, new AnimeRepository($this->entityManager), $bulkFillerService, $duplicateDetector, $deletionDetector, $convergenceService, new NullLogger());

        $sync = $this->syncFillerStub(
            [new SyncItem('42', SyncStatus::Plan, 'Trigun')],
            data: new PluginAnimeData(title: 'Trigun', type: ContractsAnimeType::Tv),
            fillableFields: ['title', 'type'],
        );

        $service->pull($this->pluginId, $sync);

        $created = array_values(array_filter($this->allAnime(), static fn (Anime $a): bool => $a->id !== $existing->id));
        $this->assertCount(1, $created);

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(['anime_ids' => [$existing->id, $created[0]->id]], $items[0]->payload);
    }

    public function testARecordGoneFromTheSourceListIsFlaggedForReview(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        // The source no longer lists this title (empty pull) — it is flagged, never deleted.
        $this->service->pull($this->pluginId, $this->syncFillerStub([], data: null));

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
        $this->assertSame(['anime_id' => $anime->id, 'deleted_from' => 'animedb-shikimori'], $items[0]->payload);
    }

    public function testRepeatedPullOfTheSameListNeverDuplicatesARow(): void
    {
        $data = new PluginAnimeData(title: 'Trigun', type: ContractsAnimeType::Tv);

        $firstRun = $this->syncFillerStub(
            [new SyncItem('42', SyncStatus::Plan, 'Trigun')],
            data: $data,
            fillableFields: ['title', 'type'],
        );
        $this->service->pull($this->pluginId, $firstRun);
        $this->entityManager->clear();

        $secondRun = $this->createMock(SyncInterface::class);
        $secondRun->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Watching, 'Trigun')]);
        $this->service->pull($this->pluginId, $secondRun);

        $all = $this->allAnime();
        $this->assertCount(1, $all);
        $this->assertSame(WatchStatus::Watching, $all[0]->getWatchStatus());
    }

    /**
     * Unlike a newly enriched title above, an Anime that's genuinely airing right now
     * (datePremiere in the past, no dateEnd yet) has a reliable Ongoing production status —
     * Anime::setWatchStatus() rejects Completed for that case, and pull() must skip just this
     * item's status update rather than aborting the run.
     */
    public function testSkipsTheStatusUpdateWhenTheLocalAnimeIsActuallyOngoing(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Watching);
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->rememberExternalId($this->pluginId, '42');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Completed, 'Trigun')]);

        $this->service->pull($this->pluginId, $sync);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    /**
     * Regression guard for the pull()-wide O(N×M) reload the review flagged (issue #257):
     * every item in the source list must resolve against the single up-front
     * indexByExternalId() catalog scan, never against a per-item resolve() call.
     * None of these three items has a local match, and the sync mock's inherited findById()
     * default-returns null, so all three are skipped — the point of this test is the single
     * index call, not creation.
     */
    public function testResolvesAWholeListThroughASingleUpFrontIndexRatherThanPerItemLookups(): void
    {
        $repository = $this->createMock(AnimeRepository::class);
        $repository->expects($this->once())
            ->method('indexByExternalId')
            ->with($this->pluginId)
            ->willReturn([]);
        $repository->expects($this->never())->method('resolve');

        $service = $this->newService($this->entityManager, $repository);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'),
            new SyncItem('2', SyncStatus::Plan, 'Trigun'),
            new SyncItem('3', SyncStatus::Plan, 'Bleach'),
        ]);

        $service->pull($this->pluginId, $sync);

        $this->assertCount(0, $this->allAnime());
    }

    /**
     * Issue #353: a dead OAuth session is not transient, so pull() must stop this run cleanly
     * instead of letting the exception propagate to a caller's retry loop — but whatever was
     * already applied before the exception (here, the status update for item '1', yielded
     * before the plugin's generator throws) stays applied rather than being rolled back.
     *
     * A second previously-synced record ('2') is deliberately absent from the partial pull: if
     * the catch block's early return were removed, DeletedFromSourceDetector would run against
     * this incomplete list and wrongly flag it, so assertCount(0, $items) below actually
     * distinguishes "detector skipped" from "detector ran but the list happened to be complete".
     */
    public function testStopsCleanlyOnAReauthRequiredExceptionKeepingAlreadyAppliedChanges(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($anime);

        $untouched = new TvAnime();
        $untouched->setTitle('Trigun')->setWatchStatus(WatchStatus::Watching);
        $untouched->rememberExternalId($this->pluginId, '2');
        $this->entityManager->persist($untouched);

        $this->entityManager->flush();
        $animeId = $anime->id;

        $pull = (function (): \Generator {
            yield new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop');

            throw new ReauthRequiredException('Refresh token is dead.');
        })();

        $sync = $this->syncFillerStub($pull, data: null);

        $this->service->pull($this->pluginId, $sync);

        $this->assertCount(2, $this->allAnime());

        // Re-read from the database rather than trusting the in-memory managed instance, so this
        // proves the catch block's flush() actually persisted the change durably.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Anime::class)->find($animeId);
        $this->assertInstanceOf(TvAnime::class, $reloaded);
        $this->assertSame(WatchStatus::Watching, $reloaded->getWatchStatus());

        // A reauth exception cuts this run short before it ever sees the full source list, so
        // the deletion-review detector must not run this run — otherwise 'Trigun' (absent from
        // the partial list above) would be wrongly flagged "disappeared from source".
        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(0, $items);
    }

    /**
     * Unlike ReauthRequiredException, a transient failure (network error, external source
     * down, ...) must still propagate uncaught, so a caller's own retry logic sees it.
     */
    public function testLetsATransientPullFailurePropagate(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willThrowException(new \RuntimeException('Source is down.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source is down.');

        $this->service->pull($this->pluginId, $sync);
    }

    /** @return list<Anime> */
    private function allAnime(): array
    {
        /* @var list<Anime> */
        return $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
