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

namespace App\Tests\Unit\Service\Sync;

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\AnimeSyncState;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\PendingSyncPush;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeSyncStateRepository;
use App\Repository\PendingSyncPushRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncProjection;
use App\Service\Sync\SyncReconciler;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Direct coverage of the I/O half of the reconciliation engine (issue #366 review) — the
 * SyncReconcilerTest branches wired through actual persistence (AnimeSyncState snapshot rows)
 * and an actual SyncInterface::push() call, rather than through the full PullSyncService
 * pipeline (BulkFillerService, CrossVendorDuplicateDetector, ...) that PullSyncServiceTest
 * exercises.
 */
final class SyncConvergenceServiceTest extends TestCase
{
    private EntityManager $entityManager;
    private PluginId $originPluginId;

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

        $this->originPluginId = new PluginId('animedb-shikimori');
    }

    /** @param array<string, SyncInterface> $syncs every active plugin, origin included where relevant */
    private function newService(array $syncs): SyncConvergenceService
    {
        $settings = [];
        foreach (array_keys($syncs) as $id) {
            $settings[$id] = ['features' => ['sync' => true]];
        }
        $path = sys_get_temp_dir().'/anime-convergence-test-'.uniqid().'.json';
        file_put_contents($path, json_encode($settings));
        $syncRegistry = new SyncRegistry($syncs, new PluginsConfigStore($path));

        return new SyncConvergenceService(
            new SyncReconciler(),
            new AnimeSyncStateRepository($this->entityManager),
            new PendingSyncPushRepository($this->entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            new NullLogger(),
        );
    }

    private function seedLastSeen(TvAnime $anime, string $participantId, WatchStatus $status, string $updatedAt): void
    {
        $this->entityManager->persist(new AnimeSyncState($anime, $participantId, $status, null, new \DateTimeImmutable($updatedAt)));
        $this->entityManager->flush();
    }

    /**
     * Correctness regression (issue #366 review, pitfall #17): a single-plugin configuration
     * where local raced ahead of the origin (its push-on-edit was dropped by the TTL — see
     * PushSyncMessageHandler) must still self-heal on the origin's own next pull — the origin
     * is not exempt from convergence just because it is $originParticipantId.
     */
    public function testAnOriginThatDriftedFromLocalGetsTheWinnerPushedBackOnItsOwnPull(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');

        // Local's own manual edit already advanced past the seeded snapshot (a dropped
        // push-on-edit is exactly this: watchProgressUpdatedAt moved on, but the origin's
        // remote copy never heard about it).
        $anime->changeWatchStatusManually(WatchStatus::Watching);
        $this->entityManager->flush();

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '1' && $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-02')));

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        // The origin's own fresh pull still reports the stale value its snapshot already has —
        // it did not itself change, local raced ahead of it.
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Plan, null),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $state = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $state);
        $this->assertSame(WatchStatus::Watching, $state->lastStatus);
    }

    /**
     * The mirror image: the origin's own reading is the sole reason `W` was picked (a genuine
     * pull, nothing else changed) — push() must not be called on it, or every ordinary pull
     * would waste a network round trip echoing a value the source just reported itself.
     */
    public function testAnOriginThatIsTheSoleChangedParticipantIsNeverPushedBackTo(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    /**
     * Forward-propagation (issue #366 pitfall #2): a third active, resolvable plugin whose own
     * last-seen snapshot disagrees with the winner receives it through SyncInterface::push().
     */
    public function testForwardPropagatesTheWinnerToAThirdActivePlugin(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('99', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-02')));

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $malState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
    }

    /**
     * Correctness regression (issue #366 review, "первый контакт затирает локаль"): a title with
     * real, pre-existing local watch history (not the "freshly created, never touched" case the
     * first-contact synthesis is meant for) reaching this class with no {@see AnimeSyncState} row
     * for it yet — an old title predating this feature, or one added after connect-seed's own
     * one-shot posev (issue #367) already ran — must not have that history silently overwritten
     * just because the origin's own incoming projection disagrees with it. Both sides belong in
     * the changed set, so a genuine divergence surfaces as a ">=2 changed, different" conflict
     * (persistent review-item) instead of a blind last-pull-wins overwrite.
     */
    public function testFirstContactWithRealLocalHistoryThatDivergesFromTheOriginRaisesAConflictInsteadOfOverwritingLocal(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->setEpisodesCount(12);
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->rememberExternalId($this->originPluginId, '1');
        // A real manual edit, not a plain setter — this is what leaves getWatchProgressUpdatedAt()
        // non-null, the signal the fix relies on to tell real history apart from a virgin record.
        $anime->changeWatchedEpisodesManually(12);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        // No AnimeSyncState rows seeded for anyone — genuinely the first-ever reconciliation for
        // this title, with the origin reporting a stale, lower progress than local's own history.
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 5),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
        $this->assertSame(12, $anime->getWatchedEpisodes());

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::NeedsCorrection, $items[0]->kind);
        $this->assertSame(['local', (string) $this->originPluginId], $items[0]->payload['participants']);
    }

    /**
     * Acceptance (issue #380): the sync results page needs the actual candidate values to offer
     * a choice between, not just the winner and the participant ids that disagreed.
     */
    public function testNeedsCorrectionPayloadCarriesACandidatePerChangedParticipant(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->setEpisodesCount(12);
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->changeWatchedEpisodesManually(12);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 5),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);

        $candidates = $items[0]->payload['candidates'];
        $this->assertCount(2, $candidates);

        $byParticipant = [];
        foreach ($candidates as $candidate) {
            $byParticipant[$candidate['participant_id']] = $candidate;
        }

        $this->assertSame('completed', $byParticipant['local']['status']);
        $this->assertSame(12, $byParticipant['local']['watched_episodes']);
        $this->assertIsInt($byParticipant['local']['updated_at']);

        $originId = (string) $this->originPluginId;
        $this->assertSame('watching', $byParticipant[$originId]['status']);
        $this->assertSame(5, $byParticipant[$originId]['watched_episodes']);
        $this->assertSame((new \DateTimeImmutable('2026-01-01'))->getTimestamp(), $byParticipant[$originId]['updated_at']);
    }

    /**
     * Acceptance (issue #380): applyManualResolution() applies the user's pick to local and pushes
     * it to every diverging active participant, reusing the same forward-propagation/snapshot
     * machinery as an ordinary reconcile() convergence.
     */
    public function testApplyManualResolutionAppliesToLocalAndPushesToDivergingParticipants(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '1' && $item->status === SyncStatus::Watching && $item->watchedEpisodes === 5))
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable(), watchedEpisodes: 5));

        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching && $item->watchedEpisodes === 5))
            ->willReturn(new SyncItem('99', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable(), watchedEpisodes: 5));

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->applyManualResolution($anime, new SyncProjection(WatchStatus::Watching, 5), $this->entityManager);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
        $this->assertSame(5, $anime->getWatchedEpisodes());

        $stateRepository = $this->entityManager->getRepository(AnimeSyncState::class);

        $localState = $stateRepository->find(['anime' => $anime, 'participantId' => 'local']);
        $this->assertInstanceOf(AnimeSyncState::class, $localState);
        $this->assertSame(WatchStatus::Watching, $localState->lastStatus);
        $this->assertSame(5, $localState->lastWatchedEpisodes);

        $originState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertSame(WatchStatus::Watching, $originState->lastStatus);

        $malState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
    }

    /**
     * Acceptance (issue #380): the pin is purely $updatedAt = now() on the manual apply — nothing
     * extra is stored. If the forward-propagation push to a participant does not land (network
     * error, same self-healing stance {@see pushTo()} already takes elsewhere), that participant's
     * snapshot is left exactly as it was before the resolution — so it re-reports the very same
     * pre-resolution value on its next sync, which must not overturn the user's choice: with no
     * new information from anyone, reconcile() has nothing to converge and local stays put.
     */
    public function testApplyManualResolutionPinsTheChoiceAgainstAStaleSourceOnTheNextSync(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');

        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willThrowException(new \RuntimeException('unreachable'));

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->applyManualResolution($anime, new SyncProjection(WatchStatus::Watching, null), $this->entityManager);
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());

        // The failed push above left the origin's snapshot untouched (still its pre-resolution
        // Plan/2026-01-01 row), so its next pull reporting that exact same value is indistinguishable
        // from "nothing changed" — not a fresh edit that ought to win arbitration.
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Plan, null),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    /**
     * Correctness regression (PR #384 review): a $chosen pair that violates a local invariant
     * (Completed while the anime is still Announced/Ongoing, see Anime::setWatchStatus()) makes
     * Anime::applyWatchProgress() reject it — local stays on its previous value and is not
     * pinned (watchProgressUpdatedAt untouched). Forwarding $chosen to other participants anyway
     * would push a value local itself never actually holds, so no push must happen here: the
     * origin's last-seen row already agrees with local's *actual*, unchanged projection.
     */
    /**
     * Acceptance (issue #861, scenario 1): local already has its own snapshot row (it previously
     * converged with some other participant) and the origin's first-ever pull disagrees with
     * local's current projection — this must raise a NeedsCorrection review-item naming both
     * candidates rather than silently adopting the origin's value the way a single-changed-
     * participant reconcile() would otherwise do.
     */
    public function testFirstContactAgainstAnAlreadySyncedLocalRaisesAReviewItemInsteadOfApplyingTheOrigin(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(26);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->changeWatchedEpisodesManually(10);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        // Local already converged with some other, now-inactive participant in the past — this is
        // the "history" the issue is about, distinct from a provably virgin local.
        $this->seedLastSeen($anime, 'some-other-source', WatchStatus::Watching, '2026-01-01');
        $this->entityManager->persist(new AnimeSyncState($anime, 'local', WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        // The origin's very first pull for this title, reporting a different progress than local.
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 3),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
        $this->assertSame(10, $anime->getWatchedEpisodes());

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::NeedsCorrection, $items[0]->kind);
        $this->assertSame(['local', (string) $this->originPluginId], $items[0]->payload['participants']);

        $originState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertNull($originState);
    }

    /**
     * Acceptance (issue #861, scenario 2): a third active, resolvable participant with its own
     * cached external id and snapshot row must not be touched at all by a first-contact
     * divergence between local and a different origin — no push, no snapshot change.
     */
    public function testFirstContactDivergenceDoesNotPushOrTouchAThirdActiveParticipant(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(26);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $anime->changeWatchedEpisodesManually(10);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->entityManager->persist(new AnimeSyncState($anime, 'local', WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->persist(new AnimeSyncState($anime, (string) $malPluginId, WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->never())->method('push');

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 3),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $malState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
        $this->assertSame(10, $malState->lastWatchedEpisodes);
    }

    /**
     * Acceptance (issue #861, scenario 3): a repeated pull of the same still-unresolved
     * disagreement from the same source must not raise a second review-item — the dedup check is
     * keyed on (anime, participant), not just anime.
     */
    public function testRepeatedFirstContactDivergenceFromTheSameOriginDoesNotDuplicateTheReviewItem(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(26);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->changeWatchedEpisodesManually(10);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->entityManager->persist(new AnimeSyncState($anime, 'local', WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 3),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 3),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $this->assertSame(10, $anime->getWatchedEpisodes());

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);

        $originState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertNull($originState);
    }

    /**
     * Correctness regression for the dedup key itself: {@see testRepeatedFirstContactDivergenceFromTheSameOriginDoesNotDuplicateTheReviewItem()}
     * would still pass if `flagFirstContactDivergence()` deduped on anime alone (the old
     * `alreadyFlagged()` behavior), because it only ever replays the *same* origin. This proves
     * the (anime, participant) key is load-bearing: a second, different origin's own first-contact
     * divergence against the same anime must raise its own review-item rather than being swallowed
     * by the first one's.
     */
    public function testFirstContactDivergenceFromTwoDifferentOriginsRaisesTwoDistinctReviewItems(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(26);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $anime->changeWatchedEpisodesManually(10);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->entityManager->persist(new AnimeSyncState($anime, 'local', WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);
        $mal = $this->createStub(SyncInterface::class);

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 3),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );
        $service->reconcilePulledItem(
            $anime,
            (string) $malPluginId,
            new SyncProjection(WatchStatus::Watching, 7),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(2, $items);
        $this->assertSame(
            [(string) $this->originPluginId, (string) $malPluginId],
            array_map(static fn (SyncReviewItem $item): mixed => $item->payload['origin_participant_id'] ?? null, $items),
        );

        $this->assertSame(10, $anime->getWatchedEpisodes());
    }

    /**
     * Acceptance (issue #861, scenario 4): a provably virgin local (no snapshot row, no watch
     * progress ever recorded) must keep the pre-existing behavior — the origin's value is applied
     * straight to local via the synthesized-agreement path, no review-item.
     */
    public function testVirginLocalStillSynthesizesAgreementAndAppliesTheOriginDirectly(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 3),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
        $this->assertSame(3, $anime->getWatchedEpisodes());

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(0, $items);
    }

    /**
     * Acceptance (issue #861, scenario 5): when the origin's first-contact projection actually
     * agrees with local's current one, no review-item is raised and the origin's own snapshot row
     * is created as usual — the new rule must only intercept a genuine divergence.
     */
    public function testFirstContactWithAMatchingProjectionTakesTheOrdinaryPathAndWritesTheOriginSnapshot(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(26);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->changeWatchedEpisodesManually(10);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->entityManager->persist(new AnimeSyncState($anime, 'local', WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, 10),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $this->assertSame(10, $anime->getWatchedEpisodes());

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(0, $items);

        $originState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertSame(WatchStatus::Watching, $originState->lastStatus);
    }

    /**
     * Acceptance (issue #861, scenario 6): the origin reports the same status as local but does
     * not report episode progress at all — SyncProjection::equals() treats a null episode count
     * as "not reported", not a value that conflicts with local's 10 — so this must not be read as
     * a divergence either.
     */
    public function testFirstContactWithMatchingStatusAndUnreportedEpisodesTakesTheOrdinaryPath(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(26);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->changeWatchedEpisodesManually(10);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->entityManager->persist(new AnimeSyncState($anime, 'local', WatchStatus::Watching, 10, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        $origin = $this->createStub(SyncInterface::class);

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(0, $items);

        // Distinguishes the ordinary path from a silent no-op early exit: the origin's own
        // snapshot row must actually get created here, or a regression that stops writing it
        // would pass this test too, yet turn every subsequent pull back into a "first contact".
        $originState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertSame(WatchStatus::Watching, $originState->lastStatus);

        $this->assertSame(10, $anime->getWatchedEpisodes());
    }

    public function testApplyManualResolutionDoesNotForwardARejectedChoiceToParticipants(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        // Completed is rejected here: a freshly-created TvAnime has no dates, so its production
        // status is Announced, never Released.
        $applied = $service->applyManualResolution($anime, new SyncProjection(WatchStatus::Completed, 12), $this->entityManager);

        $this->assertFalse($applied);
        $this->assertSame(WatchStatus::Plan, $anime->getWatchStatus());
        $this->assertNull($anime->getWatchProgressUpdatedAt());
        $this->assertNotNull($anime->getWatchProgressRejectedAt());

        $stateRepository = $this->entityManager->getRepository(AnimeSyncState::class);

        $localState = $stateRepository->find(['anime' => $anime, 'participantId' => 'local']);
        $this->assertInstanceOf(AnimeSyncState::class, $localState);
        $this->assertSame(WatchStatus::Plan, $localState->lastStatus);

        $originState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertSame(WatchStatus::Plan, $originState->lastStatus);
    }

    /**
     * Acceptance (issue #862, scenario 1): a forward-propagation push failing for a third active
     * participant (not the origin itself) marks its snapshot row push_pending — the docblock on
     * {@see SyncConvergenceService::pushTo()} promises a retry "the next time this anime is
     * reconciled", which {@see SyncReconciler::participantsToConverge()} alone cannot deliver for a
     * run where nothing actually changed. The second, no-change run must find the marker, push
     * local's current value, and clear it.
     */
    public function testAFailedForwardPropagationPushIsRetriedAndClearedOnTheNextNoChangeRun(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching))
            ->willThrowException(new \RuntimeException('network error'));

        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        // Run 1: the origin's own pull reports Watching — a genuine change local and MAL both need
        // converged to (the winner pushed to MAL is Watching, not its own stale Plan). MAL's push
        // fails.
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $stateRepository = $this->entityManager->getRepository(AnimeSyncState::class);
        $malState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertTrue($malState->pushPending);
        $this->assertSame(WatchStatus::Plan, $malState->lastStatus);

        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('99', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-03')));

        $service2 = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        // Run 2: the origin's own pull reports the same Watching value again — nothing changed for
        // anyone this time, yet MAL's marker must still trigger a retry.
        $service2->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $malState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertFalse($malState->pushPending);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
    }

    /**
     * Acceptance (issue #862, scenario 2): a no-change run with no push_pending marker anywhere
     * must not call push() on any plugin — the fix is purely marker-driven, not a "snapshot !=
     * winner" comparison that would otherwise resurrect issue #366 pitfall "снимок ≠ W".
     */
    public function testANoChangeRunWithNoMarkersNeverCallsPush(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');
        $mal = $this->createMock(SyncInterface::class);
        $mal->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Plan, null),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );
    }

    /**
     * Acceptance (issue #862, scenario 3): issue #366 pitfall #17 already established the origin is
     * not exempt from convergence; this extends that to the retry path — a push back to the origin
     * itself failing also marks its own snapshot row, and the origin gets retried on a later
     * no-change run exactly like any other participant.
     */
    public function testAFailedPushBackToTheOriginIsRetriedOnTheNextNoChangeRun(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');

        // Local's own manual edit already advanced past the seeded snapshot (a dropped
        // push-on-edit), so the origin's own stale pull is the lone target needing the winner
        // pushed back to it.
        $anime->changeWatchStatusManually(WatchStatus::Watching);
        $this->entityManager->flush();

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->status === SyncStatus::Watching))
            ->willThrowException(new \RuntimeException('network error'));

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Plan, null),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $stateRepository = $this->entityManager->getRepository(AnimeSyncState::class);
        $originState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertTrue($originState->pushPending);

        $origin2 = $this->createMock(SyncInterface::class);
        $origin2->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-03')));

        $service2 = $this->newService([(string) $this->originPluginId => $origin2]);

        // Run 2: the origin's own pull still reports its old, unchanged Plan value — no one else
        // changed either, so this is a hasChanges===false run.
        $service2->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Plan, null),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $originState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertFalse($originState->pushPending);
        $this->assertSame(WatchStatus::Watching, $originState->lastStatus);
    }

    /**
     * Acceptance (issue #862, scenario 4): a push failing twice in a row must not let the second
     * failure escape {@see SyncConvergenceService::reconcilePulledItem()} as an uncaught exception
     * — the marker stays set, exactly as if only the first attempt had ever happened.
     */
    public function testARepeatedPushFailureOnTheRetryLeavesTheMarkerSetWithoutThrowing(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $mal = $this->createStub(SyncInterface::class);
        $mal->method('push')->willThrowException(new \RuntimeException('network error'));
        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        // Run 2: still a hasChanges===false run (same Watching value again), and MAL's push fails
        // again — must not throw out of reconcilePulledItem().
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $malState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertTrue($malState->pushPending);
    }

    /**
     * Acceptance (issue #862, scenario 5): {@see SyncConvergenceService::applyManualResolution()}
     * can push to an active participant that has no {@see AnimeSyncState} row at all yet — the
     * marker for that failure cannot live on a row that doesn't exist, so it is a standalone
     * {@see PendingSyncPush} row instead. A later no-change pull from a different, already-agreeing
     * origin must still find it and retry the push, creating the row this time.
     */
    public function testAFailedPushFromManualResolutionWithNoSnapshotRowIsRetriedOnTheNextNoChangeRun(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        // Deliberately no snapshot row for MAL: this is its first-ever interaction with this title.

        $mal = $this->createStub(SyncInterface::class);
        $mal->method('push')->willThrowException(new \RuntimeException('network error'));
        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $applied = $service->applyManualResolution($anime, new SyncProjection(WatchStatus::Watching, null), $this->entityManager);
        $this->assertTrue($applied);

        $stateRepository = $this->entityManager->getRepository(AnimeSyncState::class);
        $this->assertNull($stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]));
        $this->assertNotNull($this->entityManager->find(PendingSyncPush::class, ['anime' => $anime, 'participantId' => (string) $malPluginId]));

        $mal2 = $this->createMock(SyncInterface::class);
        $mal2->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('99', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-03')));

        $service2 = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal2]);

        // The origin's own pull reports exactly the value it (and local) already agree on — a
        // hasChanges===false run.
        $service2->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $malState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
        $this->assertFalse($malState->pushPending);
        $this->assertNull($this->entityManager->find(PendingSyncPush::class, ['anime' => $anime, 'participantId' => (string) $malPluginId]));
    }

    /**
     * Acceptance (issue #862, scenario 6): when local itself rejects the chosen value (a Completed/
     * not-yet-released invariant violation, see {@see Anime::applyWatchProgress()}), nothing is
     * ever pushed to anyone — not a failed push, just none attempted — so no marker exists. A later
     * no-change run must not invent one: the rule is "retry a failed push", never "snapshot !=
     * local's current value", or it would resurrect issue #366 pitfall "снимок ≠ W" and overwrite
     * the origin with a value local itself never actually holds.
     */
    public function testLocalRejectingAManualResolutionLeavesNoMarkerSoANoChangeRunNeverPushesTheSource(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');

        $origin = $this->createMock(SyncInterface::class);
        $origin->expects($this->never())->method('push');

        $service = $this->newService([(string) $this->originPluginId => $origin]);

        // Completed is rejected here: a freshly-created TvAnime has no dates, so its production
        // status is Announced, never Released.
        $applied = $service->applyManualResolution($anime, new SyncProjection(WatchStatus::Completed, 12), $this->entityManager);
        $this->assertFalse($applied);

        // A later pull from the same origin, still reporting its own unchanged Plan value: no
        // marker exists (nothing was ever pushed), so this run must leave the origin alone.
        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Plan, null),
            new \DateTimeImmutable('2026-01-01'),
            $this->entityManager,
        );

        $originState = $this->entityManager->getRepository(AnimeSyncState::class)->find(['anime' => $anime, 'participantId' => (string) $this->originPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $originState);
        $this->assertSame(WatchStatus::Plan, $originState->lastStatus);
        $this->assertFalse($originState->pushPending);
    }

    /**
     * Acceptance (issue #862, PR #891 review): {@see ReauthRequiredException} means the plugin
     * itself needs the user to re-authorize, but PushSyncMessageHandler's own handling for that
     * condition (issue #353) only ever covers push-on-edit messages, never a winner forward-
     * propagated from reconciliation. Without a marker here, a later no-change run would never
     * retry it even after the user re-authorizes — the exact silent loss this issue fixes, just
     * reached through a different exception type. Retrying a push that again fails with
     * reauthorization required is harmless: the plugin rejects it before it ever reaches the
     * network.
     */
    public function testAReauthRequiredExceptionAlsoMarksPushPendingAndGetsRetried(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $mal = $this->createStub(SyncInterface::class);
        $mal->method('push')->willThrowException(new ReauthRequiredException('Refresh token is dead.'));
        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $service = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal]);

        $service->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $stateRepository = $this->entityManager->getRepository(AnimeSyncState::class);
        $malState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertTrue($malState->pushPending);
        $this->assertSame(WatchStatus::Plan, $malState->lastStatus);

        // The user re-authorizes; a later run where nothing else changed must still find the
        // marker and retry the push, even though nothing routed through PushSyncMessageHandler.
        $mal2 = $this->createMock(SyncInterface::class);
        $mal2->expects($this->once())
            ->method('push')
            ->with($this->callback(static fn (SyncItem $item): bool => $item->externalId === '99' && $item->status === SyncStatus::Watching))
            ->willReturn(new SyncItem('99', SyncStatus::Watching, 'Cowboy Bebop', updatedAt: new \DateTimeImmutable('2026-01-03')));

        $service2 = $this->newService([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal2]);

        $service2->reconcilePulledItem(
            $anime,
            (string) $this->originPluginId,
            new SyncProjection(WatchStatus::Watching, null),
            new \DateTimeImmutable('2026-01-02'),
            $this->entityManager,
        );

        $malState = $stateRepository->find(['anime' => $anime, 'participantId' => (string) $malPluginId]);
        $this->assertInstanceOf(AnimeSyncState::class, $malState);
        $this->assertFalse($malState->pushPending);
        $this->assertSame(WatchStatus::Watching, $malState->lastStatus);
    }

    /**
     * Atomicity regression (issue #859, PR #891 review): a forward-propagation push failing marks
     * push_pending, and {@see SyncConvergenceService::applyToLocal()} has already mutated $anime in
     * memory by the time {@see SyncConvergenceService::persistConfirmedState()} runs. If that
     * method's own transaction then fails (a later participant's flush throws), neither $anime's
     * applied projection nor the push_pending marker may end up committed on their own — both must
     * roll back together with everything else {@see SyncConvergenceService::reconcilePulledItem()}
     * confirmed this run. Before the fix, {@see SyncConvergenceService::markPushPending()} flushed
     * immediately and independently the moment the push failed, so it survived a later rollback
     * intact while $anime's change (and the snapshot that should have matched it) did not — the
     * exact half-applied state issue #859 closed.
     */
    public function testAPersistConfirmedStateFailureRollsBackBothTheLocalApplyAndThePushPendingMarker(): void
    {
        $malPluginId = new PluginId('animedb-mal');

        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->originPluginId, '1');
        $anime->rememberExternalId($malPluginId, '99');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id;

        $this->seedLastSeen($anime, 'local', WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $this->originPluginId, WatchStatus::Plan, '2026-01-01');
        $this->seedLastSeen($anime, (string) $malPluginId, WatchStatus::Plan, '2026-01-01');

        $mal = $this->createStub(SyncInterface::class);
        $mal->method('push')->willThrowException(new \RuntimeException('network error'));
        $origin = $this->createStub(SyncInterface::class);
        $origin->method('push')->willReturnCallback(static fn (SyncItem $item): SyncItem => $item);

        $settings = [(string) $this->originPluginId => ['features' => ['sync' => true]], (string) $malPluginId => ['features' => ['sync' => true]]];
        $path = sys_get_temp_dir().'/anime-convergence-test-'.uniqid().'.json';
        file_put_contents($path, json_encode($settings));
        $syncRegistry = new SyncRegistry([(string) $this->originPluginId => $origin, (string) $malPluginId => $mal], new PluginsConfigStore($path));

        // Only the 'local' participant's own save() fails — mal's and the origin's go through
        // normally, so under the pre-fix code markPushPending()'s own immediate, untransacted
        // flush (which flushes the *entire* unit of work, not just mal's row) still gets a chance
        // to silently commit $anime's already-applied local projection before persistConfirmedState
        // ever starts its transaction; only the fix keeps that mutation pending until the single
        // transaction below, where this failure then rolls it back together with everything else.
        $failingStateRepository = new class($this->entityManager) extends AnimeSyncStateRepository {
            public function save(AnimeSyncState $state, ?EntityManagerInterface $entityManager = null): void
            {
                if ($state->participantId === 'local') {
                    throw new \RuntimeException('simulated persistConfirmedState flush failure');
                }

                parent::save($state, $entityManager);
            }
        };

        $connection = $this->entityManager->getConnection();

        $service = new SyncConvergenceService(
            new SyncReconciler(),
            $failingStateRepository,
            new PendingSyncPushRepository($this->entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            new NullLogger(),
        );

        try {
            $service->reconcilePulledItem(
                $anime,
                (string) $this->originPluginId,
                new SyncProjection(WatchStatus::Watching, null),
                new \DateTimeImmutable('2026-01-02'),
                $this->entityManager,
            );
            $this->fail('Expected the simulated persistConfirmedState failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated persistConfirmedState flush failure', $exception->getMessage());
        }

        // Read the raw rows directly over the still-open DBAL connection rather than through
        // $this->entityManager — wrapInTransaction() closes it on failure (Doctrine's own reaction
        // to a failed commit), same as the recovery path PullSyncService watches for.
        $animeRow = $connection->fetchAssociative('SELECT watchStatus FROM anime WHERE id = ?', [$animeId]);
        $this->assertIsArray($animeRow);
        $this->assertSame('plan', $animeRow['watchStatus'], 'The local apply must not survive a failed persistConfirmedState on its own.');

        $malRow = $connection->fetchAssociative(
            'SELECT push_pending, last_status FROM anime_sync_state WHERE anime_id = ? AND participant_id = ?',
            [$animeId, (string) $malPluginId],
        );
        $this->assertIsArray($malRow);
        $this->assertSame(0, (int) $malRow['push_pending'], 'The push_pending marker must not survive a failed persistConfirmedState on its own.');
        $this->assertSame('plan', $malRow['last_status']);
    }
}
