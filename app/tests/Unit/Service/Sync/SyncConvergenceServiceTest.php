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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\AnimeSyncState;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeSyncStateRepository;
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
        $service->applyManualResolution($anime, new SyncProjection(WatchStatus::Completed, 12), $this->entityManager);

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
}
