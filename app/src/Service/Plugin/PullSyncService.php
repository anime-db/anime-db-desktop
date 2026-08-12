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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Sync\CrossVendorDuplicateDetector;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncProjection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Core of the pull direction of sync (issue #257): applies one plugin's
 * SyncInterface::pull() list to the local catalog idempotently, so a periodic re-run of the
 * same plugin never duplicates an already-known title.
 *
 * AnimeRepository::indexByExternalId() builds the reverse lookup off the anime_external_id
 * index (issue #297) once, up front — resolving every pulled item against that in-memory
 * map, rather than repeating the lookup per item, covers both cases the issue describes: an
 * already-synced item resolves to its known local Anime, and a "new" item that in fact
 * already has a local match (e.g. added by another plugin, or by a previous pull under a
 * stale id) is folded onto it instead of duplicated.
 *
 * A "new" item is never created as a bare title-only stub (issue #257 review): a dateless
 * placeholder can never legitimately reach Completed under Anime::setWatchStatus()'s
 * invariant, and a record carrying only a title is clutter that looks like a bug. Instead a
 * new item is created through the full BulkFillerService fill-in path, using the sync
 * plugin's own filler capability — SyncInterface extends FillerInterface (contract ^0.5,
 * anime-db-plugin-contracts#25), so every sync plugin is guaranteed to be one, no runtime
 * check needed. BulkFillerService::fillNewFrom() is used rather than fillNewFromPlugin(): the
 * external id is already known from SyncItem, so there is no need to search by title, and
 * going through the sync plugin instance directly (instead of FillerRegistry::findByPluginId())
 * means enrichment does not depend on that plugin's unrelated features.filler toggle. If
 * $sync's findById() can't resolve the id, the item is skipped entirely for this run — it
 * will be created once the source data is actually available on a later pull.
 *
 * Where/when this runs (periodic job, manual trigger, ...) is out of scope here — a future
 * caller is expected to invoke pull() once per SyncRegistry::allActive() entry, mirroring how
 * PushSyncMessageHandler consumes that same registry for the push direction (issue #214).
 * Source-side removal (issue #217) is a separate concern layered on top of this.
 *
 * Cross-vendor dedup (issue #216/#268): a pulled item that indexByExternalId() could not match
 * is exactly the case a title independently added on two sources produces — the second source's
 * external_id was never cross-referenced against the first, so it looks "new" here even though a
 * matching row may already exist under the other vendor's id. CrossVendorDuplicateDetector is run
 * against every such freshly-created Anime, once the flush below has given it an id, so it never
 * flags $anime against itself. Only genuinely new rows go through it: an item that already
 * resolved via $byExternalId is a known, previously-reviewed title, not a fresh dedup candidate.
 *
 * Create-conflict recovery (issue #297): the up-front indexByExternalId() lookup only catches a
 * race that already resolved before this run started — a *concurrent* create (another process
 * linking the same external id at the same time) is still possible, caught for real by the
 * anime_external_id UNIQUE(plugin_id, external_id) constraint inside
 * BulkFillerService::build(). That method isolates the constraint-checked insert into its own
 * flush() so a conflict there cannot take unrelated pending work down with it — but Doctrine
 * still closes this run's EntityManager as its own reaction to any failed flush (see
 * ExternalIdAlreadyClaimedException), so everything accumulated so far is flushed right before
 * every create attempt below, and once a conflict has actually happened, the remainder of this
 * pull() falls back to a throwaway EntityManager sharing the same DBAL connection — the only way
 * to keep issuing writes once the original one is closed. A *second* unrelated create failing in
 * the same run (vanishingly rare — SQLite serializes writes, so this needs two conflicts back to
 * back) is logged and skipped rather than chased further: it resolves itself on the next
 * scheduled pull, the same self-healing stance already taken for an unresolvable findById() above.
 * For the same reason, CrossVendorDuplicateDetector and DeletedFromSourceDetector are skipped
 * (logged, not run) for the rest of a run that hit this recovery path — both are wired to this
 * run's original, now-closed EntityManager, and re-wiring them to the recovery one is not worth
 * the complexity for a path this rare.
 *
 * A dead OAuth session (issue #353) is handled the same self-healing way: {@see SyncInterface::pull()}
 * throwing {@see ReauthRequiredException} — up front or mid-iteration, since it returns
 * `iterable` and a generator-backed plugin can throw from any `yield` — stops this run cleanly
 * instead of propagating to a caller's retry loop, since a dead refresh token will not fix
 * itself on a retry. Whatever this run already applied before the exception is flushed, not
 * rolled back (pull's per-item idempotency means nothing is lost by stopping early), but
 * CrossVendorDuplicateDetector/DeletedFromSourceDetector are skipped for this run — the source
 * list this run saw is only a partial prefix, so treating anything absent from it as
 * "disappeared from source" would be a false positive.
 */
final class PullSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeRepository $animeRepository,
        private readonly BulkFillerService $bulkFillerService,
        private readonly CrossVendorDuplicateDetector $duplicateDetector,
        private readonly DeletedFromSourceDetector $deletionDetector,
        private readonly SyncConvergenceService $convergenceService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * doPull() below applies every incoming projection through SyncConvergenceService, which in
     * turn only ever calls the sync-apply Anime::applyWatchProgress(), never the manual-edit
     * Anime::changeWatchStatusManually()/changeWatchedEpisodesManually() (issue #371) — so no
     * WatchProgressChangedManuallyEvent is ever recorded for a pull-applied change, and the push
     * trigger it drives never echoes back out in the first place (issue #352). Forward
     * propagation to other active plugins still happens, deliberately excluding $pluginId itself
     * — a plain parameter comparison inside SyncReconciler::participantsToConverge() (issue
     * #366), needing no runtime suppression guard; this method used to wrap doPull() in a
     * PullPushSuppressor for that purpose before #371 moved the manual/sync distinction into the
     * domain layer, which left it with nothing left to suppress.
     */
    public function pull(PluginId $pluginId, SyncInterface $sync): void
    {
        $this->doPull($pluginId, $sync);
    }

    private function doPull(PluginId $pluginId, SyncInterface $sync): void
    {
        $byExternalId = $this->animeRepository->indexByExternalId($pluginId);
        /** @var list<Anime> $newlyCreated */
        $newlyCreated = [];
        /** @var array<string, true> $presentExternalIds external ids still in the source's list */
        $presentExternalIds = [];
        // Set once this run's own EntityManager gets closed by a lost create race — see the
        // class docblock's "Create-conflict recovery" section. Everything after that point,
        // for the rest of this pull(), goes through this one instead.
        $recoveryEntityManager = null;

        try {
            foreach ($sync->pull() as $item) {
                $presentExternalIds[$item->externalId] = true;
                $anime = $byExternalId[$item->externalId] ?? null;
                $status = WatchStatusMapper::toWatchStatus($item->status);

                if ($anime === null) {
                    if ($recoveryEntityManager !== null) {
                        // A further, unrelated new item after this run's EntityManager was
                        // already closed by an earlier conflict — BulkFillerService is bound to
                        // that closed instance, so it cannot be used again this run.
                        $this->logger->warning('Skipping a new item this run: an earlier create conflict already closed this pull\'s EntityManager.', [
                            'pluginId' => (string) $pluginId,
                            'externalId' => $item->externalId,
                        ]);

                        continue;
                    }

                    // Durably commit everything accumulated so far before the one conflict-prone
                    // operation left in this run (BulkFillerService::build()'s own isolated
                    // flush): if that flush fails, nothing already-processed here is lost with it.
                    $this->entityManager->flush();

                    try {
                        $anime = $this->bulkFillerService->fillNewFrom($sync, $pluginId, $item->externalId);
                    } catch (ExternalIdAlreadyClaimedException $conflict) {
                        $recoveryEntityManager = $this->openRecoveryEntityManager();
                        $anime = $recoveryEntityManager->find(Anime::class, $conflict->animeId);
                    }

                    if ($anime === null) {
                        continue;
                    }

                    $byExternalId[$item->externalId] = $anime;
                    if ($recoveryEntityManager === null) {
                        $newlyCreated[] = $anime;
                    }
                } elseif ($recoveryEntityManager !== null) {
                    // Re-fetch through the recovery manager: $anime above is still managed by
                    // the now-closed original one, and a different EntityManager's UnitOfWork
                    // has no idea that object exists.
                    $anime = $recoveryEntityManager->find(Anime::class, $anime->id) ?? $anime;
                }

                // Reconciliation engine (issue #366): decides whether this item's projection
                // actually wins over local's current one (and over any other active plugin's
                // last-seen), applies the winner to $anime via Anime::applyWatchProgress() — which
                // itself absorbs an invariant rejection (Completed while not yet Released) by
                // flagging it rather than throwing, the same self-healing stance this loop takes
                // everywhere else — and forward-propagates to every other active, resolvable
                // plugin except $pluginId itself (origin-aware convergence, breaks the pull->push
                // echo, issue #352, without suppressing forward propagation, issue #366 pitfall #2).
                $this->convergenceService->reconcilePulledItem(
                    $anime,
                    (string) $pluginId,
                    new SyncProjection($status, $item->watchedEpisodes),
                    $item->updatedAt,
                    $recoveryEntityManager ?? $this->entityManager,
                );

                $recoveryEntityManager?->flush();
            }
        } catch (ReauthRequiredException $exception) {
            // Not transient — see the class docblock's "dead OAuth session" section. Flush
            // whatever this run already applied (idempotency preserved, nothing rolled back)
            // and stop cleanly rather than let it propagate to a caller's retry loop.
            $this->logger->warning('Sync plugin "{plugin}" needs reauthorization; stopping this pull run, not retrying.', [
                'pluginId' => (string) $pluginId,
                'exception' => $exception,
            ]);

            if ($recoveryEntityManager === null) {
                $this->entityManager->flush();
            }

            return;
        }

        if ($recoveryEntityManager === null) {
            $this->entityManager->flush();
        } else {
            // Both detectors below are wired to this run's *original* EntityManager (through
            // SyncReviewItemRepository), which a lost create race has already closed — running
            // them here would raise EntityManagerClosedException instead of the self-healing
            // this class promises. Skip them for this run and log it: any duplicate/disappeared
            // item they would have flagged is still present next pull (a newly created row keeps
            // its external_id, so it is not "new" again — but the race itself is rare enough,
            // and Doctrine serializes SQLite writes, that a second one landing in the very next
            // run to re-surface it is rarer still) — same self-healing stance already taken for
            // a second conflict earlier in this method.
            $this->logger->warning('Skipping post-pull duplicate/deletion review for this run: an earlier create conflict already closed this pull\'s EntityManager.', [
                'pluginId' => (string) $pluginId,
            ]);

            return;
        }

        foreach ($newlyCreated as $anime) {
            $this->duplicateDetector->detect($anime);
        }

        // Records this plugin synced before but that are no longer in its list — never deleted
        // automatically, flagged for review (issue #217). Newly created items are keyed in
        // $byExternalId and are present in $presentExternalIds, so they never count as removed.
        $disappeared = array_diff_key($byExternalId, $presentExternalIds);
        $this->deletionDetector->detect($pluginId, $disappeared);
    }

    /**
     * A fresh EntityManager sharing this run's original DBAL connection — the recovery path
     * once a lost create race has closed the original one (see the class docblock). Doctrine
     * only marks the ORM-level EntityManager unusable on a failed flush(); the underlying
     * connection stays open and perfectly usable, so wrapping it in a new EntityManager
     * instance is the standard, documented way to keep issuing ORM writes for the rest of
     * this run without a Doctrine\Persistence\ManagerRegistry (which would also swap the EM
     * out from under AnimeRepository/BulkFillerService — no help here, since a *further* new
     * item still can't be created through them once they hold a closed one).
     */
    private function openRecoveryEntityManager(): EntityManagerInterface
    {
        return new EntityManager($this->entityManager->getConnection(), $this->entityManager->getConfiguration());
    }
}
