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
use App\Entity\AnimeSyncState;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\EventListener\DomainEventListener;
use App\Repository\AnimeRepository;
use App\Repository\AnimeSyncStateRepository;
use App\Repository\PendingSyncPushRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\MessageBusInterface;
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

    /**
     * @param array<string, SyncInterface> $otherSyncs      active plugins other than $this->pluginId, for forward-propagation coverage
     * @param ?AnimeSyncStateRepository    $stateRepository override for the convergence engine's snapshot storage —
     *                                                      {@see throwingStateRepository()} for simulating a per-item failure
     * @param ?LoggerInterface             $logger          override for PullSyncService's own logger (not the convergence
     *                                                      engine's, which always gets a NullLogger) — used to assert on the
     *                                                      per-item-failure warning (issue #859)
     */
    private function newService(
        EntityManager $entityManager,
        AnimeRepository $animeRepository,
        array $otherSyncs = [],
        ?AnimeSyncStateRepository $stateRepository = null,
        ?LoggerInterface $logger = null,
    ): PullSyncService {
        $bulkFillerService = new BulkFillerService(
            // Never consulted by fillNewFrom() — it works off the sync plugin instance directly.
            new FillerRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($entityManager),
                $entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
                new NullLogger(),
            ),
            $entityManager,
            new NullLogger(),
            $this->createMock(MessageBusInterface::class),
            $animeRepository,
            new CachedFillerLookup(new ArrayAdapter()),
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
        $animeSyncStateRepository = new AnimeSyncStateRepository($entityManager);
        $deletionDetector = new DeletedFromSourceDetector(
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($entityManager)),
            $animeSyncStateRepository,
        );

        $convergenceService = new SyncConvergenceService(
            new SyncReconciler(),
            $stateRepository ?? $animeSyncStateRepository,
            new PendingSyncPushRepository($entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($entityManager)),
            new NullLogger(),
        );

        return new PullSyncService($entityManager, $animeRepository, $bulkFillerService, $duplicateDetector, $deletionDetector, $convergenceService, $logger ?? new NullLogger());
    }

    /**
     * A test double for the per-item isolation coverage (issue #859): throws $exception from
     * either {@see AnimeSyncStateRepository::findByAnime()} (simulating a failure early in
     * {@see SyncConvergenceService::reconcilePulledItem()}, before it has
     * mutated anything) or from {@see AnimeSyncStateRepository::save()} (simulating a failure
     * writing the snapshot row, after {@see Anime::applyWatchProgress()} has already
     * been applied in memory) — whichever method the pulled item's own processing reaches first
     * for $poisonedAnimeId specifically; every other anime is passed straight through to the
     * real repository. $closeEntityManager mirrors Doctrine's own reaction to a genuinely failed
     * flush() (see PullSyncService's class docblock, "Create-conflict recovery"), since save()'s
     * real implementation calls flush() itself.
     */
    private function throwingStateRepository(EntityManager $entityManager, int $poisonedAnimeId, \Throwable $exception, bool $onSave = false, bool $closeEntityManager = false): AnimeSyncStateRepository
    {
        return new class($entityManager, $poisonedAnimeId, $exception, $onSave, $closeEntityManager) extends AnimeSyncStateRepository {
            public function __construct(
                EntityManagerInterface $entityManager,
                private readonly int $poisonedAnimeId,
                private readonly \Throwable $exception,
                private readonly bool $onSave,
                private readonly bool $closeEntityManager,
            ) {
                parent::__construct($entityManager);
            }

            public function findByAnime(Anime $anime, ?EntityManagerInterface $entityManager = null): array
            {
                if (!$this->onSave && $anime->id === $this->poisonedAnimeId) {
                    $this->fail($entityManager);
                }

                return parent::findByAnime($anime, $entityManager);
            }

            public function save(AnimeSyncState $state, ?EntityManagerInterface $entityManager = null): void
            {
                if ($this->onSave && $state->anime->id === $this->poisonedAnimeId) {
                    $this->fail($entityManager);
                }

                parent::save($state, $entityManager);
            }

            private function fail(?EntityManagerInterface $entityManager): never
            {
                if ($this->closeEntityManager && $entityManager !== null) {
                    $entityManager->close();
                }

                throw $this->exception;
            }
        };
    }

    /**
     * Like {@see syncFillerStub()}, but with per-externalId findById() results/failures — the
     * per-item isolation coverage (issue #859) needs one item's create to fail while another's
     * succeeds in the same run, which a single shared $data return can't express.
     *
     * @param iterable<SyncItem>                            $pull
     * @param array<int|string, PluginAnimeData|\Throwable> $dataByExternalId
     * @param list<string>                                  $fillableFields
     */
    private function syncFillerStubPerItem(iterable $pull, array $dataByExternalId, array $fillableFields = []): SyncInterface&FillerInterface
    {
        return new class($pull, $dataByExternalId, $fillableFields) implements SyncInterface, FillerInterface {
            /**
             * @param iterable<SyncItem>                            $pull
             * @param array<int|string, PluginAnimeData|\Throwable> $dataByExternalId
             * @param list<string>                                  $fillableFields
             */
            public function __construct(
                private readonly iterable $pull,
                private readonly array $dataByExternalId,
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
                $entry = $this->dataByExternalId[$externalId] ?? null;
                if ($entry instanceof \Throwable) {
                    throw $entry;
                }

                return $entry;
            }

            public function getFillableFields(): array
            {
                return $this->fillableFields;
            }
        };
    }

    /**
     * SyncInterface and FillerInterface both extend ExternalIdResolutionInterface, so
     * createMockForIntersectionOfInterfaces() rejects them (it treats resolveExternalId(),
     * inherited by both, as a conflicting redeclaration) — a small concrete stub in place of a
     * generated mock instead. $pull and $data are set directly on the returned instance rather
     * than through a constructor, since PHPUnit's own mocks configure expectations the same way.
     *
     * @param iterable<SyncItem> $pull
     * @param list<string>       $fillableFields
     */
    private function syncFillerStub(iterable $pull, ?PluginAnimeData $data, array $fillableFields = []): SyncInterface&FillerInterface
    {
        return new class($pull, $data, $fillableFields) implements SyncInterface, FillerInterface {
            /**
             * @param iterable<SyncItem> $pull
             * @param list<string>       $fillableFields
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

        $completed = $this->service->pull($this->pluginId, $sync);

        $this->assertTrue($completed);
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

    /**
     * Forward-propagation coverage (issue #366 review): a second active, resolvable plugin
     * ("animedb-mal") whose own last-seen snapshot still disagrees with the winner must actually
     * receive the winning value through SyncInterface::push(), not just have $otherSyncs sit
     * unused in the test fixture.
     */
    public function testForwardPropagatesTheWinnerToAnotherActiveResolvablePlugin(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '1');
        $malPluginId = new PluginId('animedb-mal');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, null, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->pluginId, WatchStatus::Plan, null, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, null, '2026-01-01');

        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('99', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-02')));

        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), [(string) $malPluginId => $mal]);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-02'))]);

        $service->pull($this->pluginId, $sync);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());

        $malState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
    }

    /**
     * True-conflict coverage (issue #366 review): local diverged from its own last-seen (a
     * manual edit) and the pulled plugin diverges too, to a *different* value — this must both
     * arbitrate a winner by max updatedAt and raise a SyncReviewItemKind::NeedsCorrection with
     * the payload PullSyncServiceTest's happy-path tests never exercise.
     */
    public function testAConflictBetweenLocalAndThePulledPluginCreatesANeedsCorrectionReviewItem(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, null, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->pluginId, WatchStatus::Plan, null, '2026-01-01');

        // A manual local edit, diverging from last-seen[local] with a fresher updatedAt than
        // the pulled item below will carry — local should win the arbitration.
        $anime->changeWatchStatusManually(WatchStatus::Watching);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Dropped, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2020-01-01')),
        ]);

        $this->service->pull($this->pluginId, $sync);

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::NeedsCorrection, $items[0]->kind);
        $this->assertSame($anime->id, $items[0]->payload['anime_id']);
        $this->assertSame(['local', (string) $this->pluginId], $items[0]->payload['participants']);
        $this->assertSame(WatchStatus::Watching->value, $items[0]->payload['winner_status']);
    }

    private function seedLastSeen(Anime $anime, string $participantId, WatchStatus $status, ?int $watchedEpisodes, string $updatedAt): void
    {
        $this->entityManager->persist(new AnimeSyncState($anime, $participantId, $status, $watchedEpisodes, new \DateTimeImmutable($updatedAt)));
        $this->entityManager->flush();
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
     * Issue #860, scenario 9: a pulled item whose source data carries a conflicting
     * datePremiere/dateEnd pair is still created — without those two dates, rather than letting
     * PluginAnimeDataMerger's own InvalidDateRangeException escape and have the per-item
     * isolation (issue #859) skip the whole item. The rest of this run's items are unaffected
     * and pull() reports a clean completion.
     */
    public function testCreatesANewAnimeWithoutConflictingDatesWhenTheSourceDataViolatesTheInvariant(): void
    {
        $sync = $this->syncFillerStubPerItem(
            [
                new SyncItem('1', SyncStatus::Plan, 'Trigun'),
                new SyncItem('2', SyncStatus::Plan, 'Bleach'),
            ],
            dataByExternalId: [
                '1' => new PluginAnimeData(
                    title: 'Trigun',
                    type: ContractsAnimeType::Tv,
                    datePremiere: new \DateTimeImmutable('2020-06-01'),
                    dateEnd: new \DateTimeImmutable('2020-01-01'),
                ),
                '2' => new PluginAnimeData(title: 'Bleach', type: ContractsAnimeType::Tv),
            ],
            fillableFields: ['title', 'type', 'datePremiere', 'dateEnd'],
        );

        $completed = $this->service->pull($this->pluginId, $sync);

        $this->assertTrue($completed);
        $created = $this->allAnime();
        $this->assertCount(2, $created);

        $byTitle = [];
        foreach ($created as $anime) {
            $byTitle[$anime->getTitle()] = $anime;
        }

        $this->assertNull($byTitle['Trigun']->getDatePremiere());
        $this->assertNull($byTitle['Trigun']->getDateEnd());
        $this->assertArrayHasKey('Bleach', $byTitle);
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
                new NullLogger(),
            ),
            $this->entityManager,
            new NullLogger(),
            $this->createMock(MessageBusInterface::class),
            new AnimeRepository($this->entityManager),
            new CachedFillerLookup(new ArrayAdapter()),
        );
        $duplicateDetector = new CrossVendorDuplicateDetector(
            $resolver,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
        );
        $syncRegistry = new SyncRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-reg-'.uniqid().'.json'));
        $animeSyncStateRepository = new AnimeSyncStateRepository($this->entityManager);
        $deletionDetector = new DeletedFromSourceDetector(
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            $animeSyncStateRepository,
        );
        $convergenceService = new SyncConvergenceService(
            new SyncReconciler(),
            $animeSyncStateRepository,
            new PendingSyncPushRepository($this->entityManager),
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

    /**
     * Scenario 2 (issue #863): a record that carries an AnimeSyncState snapshot row for this
     * plugin — written by a prior pull/push reconciliation, seeded here via seedLastSeen() to
     * stand in for that — is genuinely a list item the source once confirmed, so its absence from
     * the current pull is flagged for review exactly as before this issue.
     */
    public function testARecordGoneFromTheSourceListIsFlaggedForReview(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->seedLastSeen($anime, (string) $this->pluginId, WatchStatus::Plan, null, '2026-01-01');

        // The source no longer lists this title (empty pull) — it is flagged, never deleted.
        $this->service->pull($this->pluginId, $this->syncFillerStub([], data: null));

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
        $this->assertSame(['anime_id' => $anime->id, 'deleted_from' => 'animedb-shikimori'], $items[0]->payload);
    }

    /**
     * Scenario 1 (issue #863): a record with only a cached external_id for this plugin — no
     * AnimeSyncState snapshot row, exactly what a filler, bulk-fill, or scan leaves behind without
     * the source ever having listed the title — must not be flagged just because this run's pull
     * list happens not to mention it; the source never confirmed it as a list item in the first
     * place.
     */
    public function testARecordWithOnlyACachedExternalIdAndNoSyncSnapshotIsNotFlaggedWhenAbsentFromTheList(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->service->pull($this->pluginId, $this->syncFillerStub([], data: null));

        $this->assertCount(0, $this->entityManager->getRepository(SyncReviewItem::class)->findAll());
    }

    /**
     * Scenario 1 variant (issue #863 review): snapshot rows existing for *other* participants
     * ('local', and another plugin genuinely synced through) must not make this plugin's own
     * cached-external_id-only record look confirmed — an implementation that checks "does any
     * AnimeSyncState row exist for this anime" instead of "...for this plugin specifically" would
     * wrongly flag it here.
     */
    public function testARecordWithSnapshotRowsForOtherParticipantsButNotThisPluginIsNotFlaggedWhenAbsentFromTheList(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, null, '2026-01-01');
        $this->seedLastSeen($anime, 'animedb-mal', WatchStatus::Plan, null, '2026-01-01');

        $this->service->pull($this->pluginId, $this->syncFillerStub([], data: null));

        $this->assertCount(0, $this->entityManager->getRepository(SyncReviewItem::class)->findAll());
    }

    /**
     * Symmetric gap (issue #863 review): this plugin's own first pull of the title diverged from
     * local's already-established history (issue #861) and is still sitting as an unresolved
     * NeedsCorrection — {@see SyncConvergenceService::reconcilePulledItem()} deliberately withholds
     * this plugin's AnimeSyncState snapshot row while that item stays unresolved, even though this
     * plugin's pull genuinely did list the title. A check keyed only on the snapshot row would
     * never flag the title's later, genuine removal from this plugin's list — the review signal
     * would be silently lost.
     */
    public function testARecordWithAPendingFirstContactDivergenceForThisPluginIsStillFlaggedWhenAbsentFromTheList(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        (new SyncReviewService(new SyncReviewItemRepository($this->entityManager)))->create(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $anime->id,
            'origin_participant_id' => (string) $this->pluginId,
            'participants' => ['local', (string) $this->pluginId],
            'candidates' => [],
        ]);

        $this->service->pull($this->pluginId, $this->syncFillerStub([], data: null));

        $items = array_values(array_filter(
            $this->entityManager->getRepository(SyncReviewItem::class)->findAll(),
            fn (SyncReviewItem $item): bool => $item->kind !== SyncReviewItemKind::NeedsCorrection,
        ));
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
        $this->assertSame(['anime_id' => $anime->id, 'deleted_from' => (string) $this->pluginId], $items[0]->payload);
    }

    /**
     * Scenario 3 (issue #863): a record with only a cached external_id for this plugin — no
     * AnimeSyncState snapshot row — that is present in the pull list is still matched through
     * $byExternalId and updated in place, not duplicated; only $disappeared's computation is
     * narrowed by this issue, never the $byExternalId lookup pulled items resolve against.
     */
    public function testARecordWithOnlyACachedExternalIdAndNoSyncSnapshotIsMatchedWhenPresentInTheList(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('77', SyncStatus::Watching, 'Trigun')]);

        $this->service->pull($this->pluginId, $sync);

        $this->assertCount(1, $this->allAnime());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
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
     *
     * The `false` return (issue #381 review) is what lets a caller like
     * {@see \App\MessageHandler\SyncSeedMessageHandler} tell this apart from an actually-completed
     * run — asserted here alongside the already-applied-changes behavior above.
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

        $completed = $this->service->pull($this->pluginId, $sync);

        $this->assertFalse($completed);
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
     * down, ...) must still propagate uncaught, so a caller's own retry logic sees it — this is
     * deliberately not covered by the per-item isolation added for issue #859: the iterator
     * itself failing (as opposed to a single yielded item's own processing) means the rest of
     * the source's list is genuinely unreachable, not just one bad item to skip over.
     */
    public function testLetsATransientPullFailurePropagate(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '77');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willThrowException(new \RuntimeException('Source is down.'));

        try {
            $this->service->pull($this->pluginId, $sync);
            $this->fail('Expected the iterator\'s own RuntimeException to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Source is down.', $exception->getMessage());
        }

        // The iterator failed before this run ever saw a single item, so the pre-existing
        // 'Trigun' must not be flagged "disappeared from source" — DeletedFromSourceDetector
        // (and CrossVendorDuplicateDetector) must not run at all for this run.
        $this->assertCount(0, $this->entityManager->getRepository(SyncReviewItem::class)->findAll());
    }

    /**
     * Criterion 1 (issue #859): an exception from {@see SyncConvergenceService::reconcilePulledItem()}
     * (here, its very first call, {@see AnimeSyncStateRepository::findByAnime()}) must not abort
     * the run — the item is skipped, but items before and after it in the source's list are
     * still applied, and pull() still reports a completed run.
     */
    public function testSkipsAnItemThatFailsDuringReconciliationButStillAppliesTheOthers(): void
    {
        $before = new TvAnime();
        $before->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $before->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($before);

        $failing = new TvAnime();
        $failing->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $failing->rememberExternalId($this->pluginId, '2');
        $this->entityManager->persist($failing);

        $after = new TvAnime();
        $after->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $after->rememberExternalId($this->pluginId, '3');
        $this->entityManager->persist($after);

        $this->entityManager->flush();
        $failingId = $failing->id ?? throw new \LogicException('id must be set after flush');

        $stateRepository = $this->throwingStateRepository($this->entityManager, $failingId, new \RuntimeException('Simulated reconciliation failure.'));
        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), stateRepository: $stateRepository);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'),
            new SyncItem('2', SyncStatus::Watching, 'Bleach'),
            new SyncItem('3', SyncStatus::Watching, 'Trigun'),
        ]);

        $completed = $service->pull($this->pluginId, $sync);

        $this->assertTrue($completed);
        $this->assertSame(WatchStatus::Watching, $before->getWatchStatus());
        $this->assertSame(WatchStatus::Plan, $failing->getWatchStatus());
        $this->assertSame(WatchStatus::Watching, $after->getWatchStatus());
    }

    /**
     * Criterion 2 (issue #859): an exception while creating a brand-new item (here, the sync
     * plugin's own findById(), called from {@see BulkFillerService::fillNewFrom()}) must not
     * abort the run — other new items in the same list are still created.
     */
    public function testSkipsAnItemThatFailsWhileCreatingANewAnimeButStillAppliesTheOthers(): void
    {
        $sync = $this->syncFillerStubPerItem(
            [
                new SyncItem('1', SyncStatus::Watching, 'Trigun'),
                new SyncItem('2', SyncStatus::Plan, 'Bleach'),
            ],
            dataByExternalId: [
                '1' => new \RuntimeException('Simulated plugin findById() failure.'),
                '2' => new PluginAnimeData(title: 'Bleach', type: ContractsAnimeType::Tv),
            ],
            fillableFields: ['title', 'type'],
        );

        $completed = $this->service->pull($this->pluginId, $sync);

        $this->assertTrue($completed);
        $created = $this->allAnime();
        $this->assertCount(1, $created);
        $this->assertSame('Bleach', $created[0]->getTitle());
    }

    /**
     * Criterion 3 (issue #859): PullSyncService's own warning for a skipped item must identify
     * which plugin and which external id failed, and carry the exception — the log line a
     * server operator (or a future periodic-pull job, issue #870) would need to find the one bad
     * title in a list of hundreds.
     */
    public function testLogsAWarningIdentifyingThePluginAndExternalIdOfASkippedItem(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '42');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('id must be set after flush');

        $exception = new \RuntimeException('Simulated reconciliation failure.');
        $expectedPluginId = (string) $this->pluginId;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->isType('string'),
                $this->callback(static function (array $context) use ($expectedPluginId, $exception): bool {
                    return ($context['pluginId'] ?? null) === $expectedPluginId
                        && ($context['externalId'] ?? null) === '42'
                        && ($context['exception'] ?? null) === $exception;
                }),
            );

        $stateRepository = $this->throwingStateRepository($this->entityManager, $animeId, $exception);
        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), stateRepository: $stateRepository, logger: $logger);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Watching, 'Trigun')]);

        $service->pull($this->pluginId, $sync);
    }

    /**
     * Criterion 4 (issue #859): a skipped item must stay in $presentExternalIds — it was
     * genuinely in the source's list — so DeletedFromSourceDetector (which still must run after
     * a non-EntityManager-closing failure) never flags it as removed. A second, genuinely
     * removed record (absent from the pull list entirely) is still flagged, proving the detector
     * actually ran rather than being skipped wholesale.
     */
    public function testDeletedFromSourceDetectorRunsAfterASkipAndExcludesTheSkippedItem(): void
    {
        $failing = new TvAnime();
        $failing->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $failing->rememberExternalId($this->pluginId, '2');
        $this->entityManager->persist($failing);

        $gone = new TvAnime();
        $gone->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $gone->rememberExternalId($this->pluginId, '3');
        $this->entityManager->persist($gone);

        $this->entityManager->flush();
        $failingId = $failing->id ?? throw new \LogicException('id must be set after flush');
        $goneId = $gone->id;
        // A prior pull/push already confirmed 'Trigun' as a list item for this plugin (issue
        // #863) — without this snapshot row, a merely cached external_id would not be enough to
        // flag it as disappeared.
        $this->seedLastSeen($gone, (string) $this->pluginId, WatchStatus::Plan, null, '2026-01-01');

        $stateRepository = $this->throwingStateRepository($this->entityManager, $failingId, new \RuntimeException('Simulated reconciliation failure.'));
        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), stateRepository: $stateRepository);

        $sync = $this->createMock(SyncInterface::class);
        // 'Bleach' (external id '2') is present but fails; 'Trigun' (external id '3') is
        // genuinely absent from this run's list.
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('2', SyncStatus::Watching, 'Bleach')]);

        $service->pull($this->pluginId, $sync);

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
        $this->assertSame($goneId, $items[0]->payload['anime_id']);
    }

    /**
     * Criterion 5 (issue #859): CrossVendorDuplicateDetector must still run against records
     * genuinely created during a run that also skipped a failing item — the skip must not
     * suppress dedup detection for the rest of the list.
     */
    public function testCrossVendorDuplicateDetectorRunsForItemsCreatedInARunWithASkippedItem(): void
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
                new NullLogger(),
            ),
            $this->entityManager,
            new NullLogger(),
            $this->createMock(MessageBusInterface::class),
            new AnimeRepository($this->entityManager),
            new CachedFillerLookup(new ArrayAdapter()),
        );
        $duplicateDetector = new CrossVendorDuplicateDetector(
            $resolver,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
        );
        $syncRegistry = new SyncRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-reg-'.uniqid().'.json'));
        $animeSyncStateRepository = new AnimeSyncStateRepository($this->entityManager);
        $deletionDetector = new DeletedFromSourceDetector(
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            $animeSyncStateRepository,
        );
        $convergenceService = new SyncConvergenceService(
            new SyncReconciler(),
            $animeSyncStateRepository,
            new PendingSyncPushRepository($this->entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            new NullLogger(),
        );
        $service = new PullSyncService($this->entityManager, new AnimeRepository($this->entityManager), $bulkFillerService, $duplicateDetector, $deletionDetector, $convergenceService, new NullLogger());

        $sync = $this->syncFillerStubPerItem(
            [
                new SyncItem('1', SyncStatus::Watching, 'Bleach'),
                new SyncItem('42', SyncStatus::Plan, 'Trigun'),
            ],
            dataByExternalId: [
                '1' => new \RuntimeException('Simulated plugin findById() failure.'),
                '42' => new PluginAnimeData(title: 'Trigun', type: ContractsAnimeType::Tv),
            ],
            fillableFields: ['title', 'type'],
        );

        $service->pull($this->pluginId, $sync);

        $created = array_values(array_filter($this->allAnime(), static fn (Anime $a): bool => $a->id !== $existing->id));
        $this->assertCount(1, $created);

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(['anime_ids' => [$existing->id, $created[0]->id]], $items[0]->payload);
    }

    /**
     * Criterion 6 (issue #859): reconcilePulledItem() applies the winning projection to Anime in
     * memory (Anime::applyWatchProgress()) before it ever writes the snapshot row — if the
     * snapshot write then fails, that in-memory change must be rolled back, or a later flush()
     * in the same run (here, the one belonging to a different, successfully-processed item)
     * would durably commit it anyway. The rollback itself is {@see
     * SyncConvergenceService}'s wrapInTransaction() around the whole item's snapshot writes
     * (issue #859 review, "частичный коммит в рамках одного элемента") — a real Doctrine flush
     * failure there always closes $this->entityManager, which is why this run's own return value
     * must honestly report that (see PullSyncService's class docblock), and why the assertions
     * below read back through a fresh EntityManager rather than the now-closed original.
     */
    public function testRollsBackAnimeChangesInMemoryWhenTheSnapshotWriteFailsBeforeALaterFlush(): void
    {
        $failing = new TvAnime();
        $failing->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $failing->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($failing);

        $other = new TvAnime();
        $other->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $other->rememberExternalId($this->pluginId, '2');
        $this->entityManager->persist($other);

        $this->entityManager->flush();
        $failingId = $failing->id ?? throw new \LogicException('id must be set after flush');
        $otherId = $other->id ?? throw new \LogicException('id must be set after flush');

        // onSave: the failure happens writing the snapshot row, after applyToLocal() has already
        // mutated $failing's watchStatus in memory for this item.
        $stateRepository = $this->throwingStateRepository($this->entityManager, $failingId, new \RuntimeException('Simulated snapshot write failure.'), onSave: true);
        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), stateRepository: $stateRepository);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'),
            new SyncItem('2', SyncStatus::Watching, 'Trigun'),
        ]);

        $completed = $service->pull($this->pluginId, $sync);

        // The snapshot write's failure closes $this->entityManager for real (see the class
        // docblock) — this run did not finish in a state a caller can keep building on.
        $this->assertFalse($completed);

        // Re-read through a fresh EntityManager sharing the same DBAL connection, rather than the
        // now-closed $this->entityManager, so this proves the rollback (not just that the test
        // never re-applied the change) is what kept the bad write out, despite 'Trigun'
        // successfully flushing its own change later in the same run.
        $freshEntityManager = new EntityManager($this->entityManager->getConnection(), $this->entityManager->getConfiguration());
        $reloadedFailing = $freshEntityManager->getRepository(Anime::class)->find($failingId);
        $this->assertInstanceOf(TvAnime::class, $reloadedFailing);
        $this->assertSame(WatchStatus::Plan, $reloadedFailing->getWatchStatus());

        $reloadedOther = $freshEntityManager->getRepository(Anime::class)->find($otherId);
        $this->assertInstanceOf(TvAnime::class, $reloadedOther);
        $this->assertSame(WatchStatus::Watching, $reloadedOther->getWatchStatus());
    }

    /**
     * Criterion 7 (issue #859): a Doctrine exception that closes the EntityManager on one item
     * (simulated here exactly as Doctrine itself would react to a genuinely failed flush() — see
     * {@see throwingStateRepository()}) must not abort the run either — the rest of the list is
     * applied through the same create-conflict recovery EntityManager {@see
     * PullSyncService::openRecoveryEntityManager()} already provides, detectors are skipped for
     * this run (the existing recovery-path rule), and the skip is logged.
     */
    public function testContinuesThroughARecoveryEntityManagerWhenAFailureClosesTheOriginalOne(): void
    {
        $failing = new TvAnime();
        $failing->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $failing->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($failing);

        $other = new TvAnime();
        $other->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $other->rememberExternalId($this->pluginId, '2');
        $this->entityManager->persist($other);

        // Absent from this run's pull list — would be flagged by DeletedFromSourceDetector if
        // detectors ran for this run, which they must not once recovery kicks in.
        $missing = new TvAnime();
        $missing->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $missing->rememberExternalId($this->pluginId, '3');
        $this->entityManager->persist($missing);

        $this->entityManager->flush();
        $failingId = $failing->id ?? throw new \LogicException('id must be set after flush');
        $otherId = $other->id ?? throw new \LogicException('id must be set after flush');

        $exception = new \RuntimeException('Simulated Doctrine failure.');
        $stateRepository = $this->throwingStateRepository(
            $this->entityManager,
            $failingId,
            $exception,
            onSave: true,
            closeEntityManager: true,
        );
        // Two warnings are expected: PullSyncService's own per-item skip, and the existing
        // recovery-path rule that also logs skipping post-pull duplicate/deletion review — both
        // satisfy criterion 7's "log entry about the skip". Captured via a callback rather than
        // asserted by count alone, so this actually proves the *first* warning is about the
        // failing item specifically (its externalId and exception), not some unrelated warning
        // from a different branch (issue #859 review).
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')
            ->willReturnCallback(function (string $message, array $context) use (&$warnings): void {
                $warnings[] = [$message, $context];
            });
        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), stateRepository: $stateRepository, logger: $logger);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'),
            new SyncItem('2', SyncStatus::Watching, 'Trigun'),
        ]);

        $completed = $service->pull($this->pluginId, $sync);

        // The failure genuinely closed $this->entityManager (see PullSyncService's class
        // docblock) — a caller must be told this run did not finish in a reusable state.
        $this->assertFalse($completed);

        $this->assertCount(2, $warnings);
        [$itemWarningMessage, $itemWarningContext] = $warnings[0];
        $this->assertStringContainsString('Skipping a pull item', $itemWarningMessage);
        $this->assertSame((string) $this->pluginId, $itemWarningContext['pluginId'] ?? null);
        $this->assertSame('1', $itemWarningContext['externalId'] ?? null);
        $this->assertSame($exception, $itemWarningContext['exception'] ?? null);

        [$skipWarningMessage] = $warnings[1];
        $this->assertStringContainsString('Skipping post-pull duplicate/deletion review', $skipWarningMessage);

        // $this->entityManager is closed by now (the simulated Doctrine reaction) — read back
        // through a fresh EntityManager sharing the same DBAL connection, exactly the recovery
        // pattern PullSyncService itself falls back on.
        $freshEntityManager = new EntityManager($this->entityManager->getConnection(), $this->entityManager->getConfiguration());

        // The main promise of this isolation (issue #859 review): the failing item's own change
        // never got committed, despite the next item's own write succeeding right after it.
        $reloadedFailing = $freshEntityManager->getRepository(Anime::class)->find($failingId);
        $this->assertInstanceOf(TvAnime::class, $reloadedFailing);
        $this->assertSame(WatchStatus::Plan, $reloadedFailing->getWatchStatus());

        $reloadedOther = $freshEntityManager->getRepository(Anime::class)->find($otherId);
        $this->assertInstanceOf(TvAnime::class, $reloadedOther);
        $this->assertSame(WatchStatus::Watching, $reloadedOther->getWatchStatus());

        $this->assertCount(0, $freshEntityManager->getRepository(SyncReviewItem::class)->findAll());
    }

    /**
     * Comment-requested regression (issue #859 review, PR #884): the atomicity the two tests
     * above exercise for a brand-new snapshot row must hold for an *existing* one too — a prior
     * run's {@see AnimeSyncState} row, mutated in place by update() before this item's write
     * fails, must not end up durably holding that in-memory mutation while Anime itself stays on
     * the old value (the exact split the review flagged: "запишет снимок origin с новым
     * значением, а Anime останется со старым").
     */
    public function testRollsBackAnExistingSnapshotRowWhenTheItemsWriteFails(): void
    {
        $failing = new TvAnime();
        $failing->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $failing->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($failing);

        $other = new TvAnime();
        $other->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $other->rememberExternalId($this->pluginId, '2');
        $this->entityManager->persist($other);

        $this->entityManager->flush();
        $failingId = $failing->id ?? throw new \LogicException('id must be set after flush');
        $otherId = $other->id ?? throw new \LogicException('id must be set after flush');

        // A snapshot row left over from a previous run — update()d in place (not re-created) as
        // soon as this run's reconciliation picks a winner, before the write that fails.
        $this->entityManager->persist(new AnimeSyncState($failing, (string) $this->pluginId, WatchStatus::Plan, null, new \DateTimeImmutable('-2 days')));
        $this->entityManager->flush();

        // onSave: $failing's own snapshot row is the first one persistConfirmedState() writes
        // for this item (the origin's row, ahead of local's), so this fails before anything else
        // in the item gets a chance to commit.
        $stateRepository = $this->throwingStateRepository($this->entityManager, $failingId, new \RuntimeException('Simulated snapshot write failure.'), onSave: true);
        $service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager), stateRepository: $stateRepository);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'),
            new SyncItem('2', SyncStatus::Watching, 'Trigun'),
        ]);

        $completed = $service->pull($this->pluginId, $sync);

        $this->assertFalse($completed);

        $freshEntityManager = new EntityManager($this->entityManager->getConnection(), $this->entityManager->getConfiguration());

        $reloadedFailing = $freshEntityManager->getRepository(Anime::class)->find($failingId);
        $this->assertInstanceOf(TvAnime::class, $reloadedFailing);
        $this->assertSame(WatchStatus::Plan, $reloadedFailing->getWatchStatus());

        $reloadedState = $freshEntityManager->getRepository(AnimeSyncState::class)->find([
            'anime' => $reloadedFailing,
            'participantId' => (string) $this->pluginId,
        ]);
        $this->assertInstanceOf(AnimeSyncState::class, $reloadedState);
        $this->assertSame(WatchStatus::Plan, $reloadedState->lastStatus);

        $reloadedOther = $freshEntityManager->getRepository(Anime::class)->find($otherId);
        $this->assertInstanceOf(TvAnime::class, $reloadedOther);
        $this->assertSame(WatchStatus::Watching, $reloadedOther->getWatchStatus());
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
