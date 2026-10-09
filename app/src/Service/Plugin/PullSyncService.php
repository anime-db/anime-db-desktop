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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Model\AnimeType as ContractAnimeType;
use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Sync\CrossVendorDuplicateDetector;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncProjection;
use App\Service\Sync\TypeMismatchDetector;
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
 * pull() runs from the connect-seed (SyncSeedMessageHandler, issue #381) and periodically
 * (SyncPullSchedule → SyncPullTickMessageHandler → SyncPullMessageHandler, issue #870: an hourly
 * tick, at most once per SyncPullGate::MAX_AGE_SECONDS per seeded active plugin), mirroring how
 * PushSyncMessageHandler consumes SyncRegistry for the push direction (issue #214).
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
 * "disappeared from source" would be a false positive. pull() reports this case back to its
 * caller as a `false` return (issue #381 review) rather than swallowing it silently — a caller
 * that treats "pull ran" as a one-time completion signal (connect-seed's own dispatch flag,
 * {@see \App\MessageHandler\SyncSeedMessageHandler}) needs to tell "actually pulled" apart from
 * "stopped short on a dead OAuth session" to know whether to retry once credentials are fixed.
 *
 * Per-item isolation (issue #859): unlike the dead-OAuth-session case above, a failure while
 * processing one specific item (fillNewFrom(), reconcilePulledItem(), or the per-item flush()
 * that follows it) is not a reason to stop the run — the rest of the plugin's list is still
 * reachable, so skipping just this one item and continuing is the self-healing move. The item
 * stays in $presentExternalIds (it was genuinely in the source's list), its exception is logged
 * as a warning with the plugin and external id, and anything it already mutated in memory is
 * discarded so a later flush() in this same run cannot durably commit a half-applied item: a
 * lone leftover Anime change is handled here via EntityManagerInterface::refresh(), while
 * everything reconcilePulledItem() itself persists (the Anime change together with every
 * confirmed participant's {@see \App\Entity\AnimeSyncState} snapshot row) is closed out as one
 * {@see EntityManagerInterface::wrapInTransaction()} unit inside {@see
 * \App\Service\Sync\SyncConvergenceService} — so a later participant's failed write there rolls
 * every earlier one in the same item back too, rather than leaving some of them durably
 * committed while others are not (issue #859 review, "частичный коммит в рамках одного
 * элемента"). That transaction failing closes the EntityManager every time (Doctrine's own
 * reaction to a failed commit), so this falls onto the same recovery EntityManager as the
 * create-conflict path above (opening one if this run has not already); detectors are skipped
 * for this run under the same rule a lost create race already follows. Unlike that race, though,
 * this really does close the *original*, request-scoped EntityManager this run was given — pull()
 * reports that back to its caller as a `false` return (same mechanism already used for a dead
 * OAuth session) rather than claiming success over an EntityManager the caller can no longer use.
 * Deleted-locally tombstones (issue #916): a new item whose external id has a
 * {@see \App\Entity\SyncTombstone} is not created, so a record the user deleted does not come back
 * with the next pull. See {@see doPull()}.
 *
 * ReauthRequiredException is explicitly excluded from this isolation — it keeps stopping the
 * whole run, as described above.
 */
final class PullSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeRepository $animeRepository,
        private readonly BulkFillerService $bulkFillerService,
        private readonly CrossVendorDuplicateDetector $duplicateDetector,
        private readonly DeletedFromSourceDetector $deletionDetector,
        private readonly TypeMismatchDetector $typeMismatchDetector,
        private readonly SyncConvergenceService $convergenceService,
        private readonly SyncTombstoneRepository $tombstoneRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * doPull() below applies every incoming projection through SyncConvergenceService, which in
     * turn only ever calls the sync-apply Anime::applyWatchProgress(), never the manual-edit
     * Anime::changeWatchStatusManually()/changeWatchedEpisodesManually() (issue #371) — so no
     * WatchProgressChangedManuallyEvent is ever recorded for a pull-applied change, and the push
     * trigger it drives never echoes back out in the first place (issue #352). Forward
     * propagation to other active plugins still happens, including back to $pluginId itself when
     * its own snapshot has genuinely drifted from the winner (issue #366 review) — the plain
     * equals() check inside SyncReconciler::participantsToConverge() only skips a target whose
     * current reading already agrees with the winner, which is what keeps $pluginId out of it in
     * the ordinary "it is the source of the winning value" case, no runtime suppression guard
     * needed; this method used to wrap doPull() in a PullPushSuppressor for that purpose before
     * #371 moved the manual/sync distinction into the domain layer, which left it with nothing
     * left to suppress.
     */
    /**
     * @param \Closure():void|null $onItem called before each pulled item is processed, so a caller
     *                                     holding a lock around a long run can keep its heartbeat fresh
     *
     * @return bool whether this run finished in a state a caller can keep building on — `false`
     *              means either it stopped early on {@see ReauthRequiredException} (see the class
     *              docblock's "dead OAuth session" section) and applied nothing beyond what it saw
     *              before that point, or a per-item failure genuinely closed the shared
     *              EntityManager this run was given (see "Per-item isolation" below); in both
     *              cases the caller must not treat this as a clean, retry-free success
     */
    public function pull(PluginId $pluginId, SyncInterface $sync, ?\Closure $onItem = null): bool
    {
        return $this->doPull($pluginId, $sync, $onItem);
    }

    private function doPull(PluginId $pluginId, SyncInterface $sync, ?\Closure $onItem): bool
    {
        $byExternalId = $this->animeRepository->indexByExternalId($pluginId);
        // Titles the user deleted locally (issue #916), loaded once per run like the index above.
        $tombstones = $this->tombstoneRepository->indexByPlugin($pluginId);
        /** @var list<Anime> $newlyCreated */
        $newlyCreated = [];
        /** @var list<array{animeId: int, type: AnimeType, sourceType: ContractAnimeType}> $typeReports existing records whose source reports a type */
        $typeReports = [];
        /** @var array<string, true> $presentExternalIds external ids still in the source's list */
        $presentExternalIds = [];
        // Set once this run's own EntityManager gets closed by a lost create race — see the
        // class docblock's "Create-conflict recovery" section. Everything after that point,
        // for the rest of this pull(), goes through this one instead.
        $recoveryEntityManager = null;

        try {
            foreach ($sync->pull() as $item) {
                $onItem?->__invoke();
                $presentExternalIds[$item->externalId] = true;
                $anime = $byExternalId[$item->externalId] ?? null;
                $status = WatchStatusMapper::toWatchStatus($item->status);
                $isNew = $anime === null;

                try {
                    if ($anime === null) {
                        // Only checked for an item with no live record: a live record with the same
                        // external id (re-added by the user after the deletion) always wins above, and
                        // the tombstone is never removed. The item stays in $presentExternalIds.
                        if (isset($tombstones[$item->externalId])) {
                            continue;
                        }

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
                            // Issue #839: BulkFillerService no longer closes $this->entityManager to
                            // report this conflict (it links the external id via a DBAL `INSERT ...
                            // ON CONFLICT DO NOTHING` instead of a failing ORM flush), so it is no
                            // longer closing's own side effect (EntityManager::close() clears the
                            // UnitOfWork first) that wipes $this->entityManager's identity map for
                            // us. Without an explicit clear() here, $this->entityManager would still
                            // hold whatever stale copy of the winning Anime it last loaded (e.g. the
                            // very row $winner above was just persisted through), which this run's
                            // every remaining update goes through $recoveryEntityManager instead —
                            // a caller reusing this shared, request-scoped EntityManager after this
                            // pull() returns needs a clean slate, not a copy this run's own recovery
                            // writes never touch.
                            $this->entityManager->clear();
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
                    // plugin whose current reading disagrees with the winner, $pluginId included
                    // (origin-aware convergence, breaks the pull->push echo, issue #352, without
                    // suppressing forward propagation — even back to $pluginId itself, issue #366
                    // review — pitfall #2).
                    $this->convergenceService->reconcilePulledItem(
                        $anime,
                        (string) $pluginId,
                        new SyncProjection($status, $item->watchedEpisodes),
                        $item->updatedAt,
                        $recoveryEntityManager ?? $this->entityManager,
                    );

                    $recoveryEntityManager?->flush();

                    // A pull never changes a type: for a record that existed before this run the
                    // source's type is only collected here and compared by TypeMismatchDetector after
                    // the loop. A new record is created by the filler and has no type to compare.
                    if ($item->type !== null && !$isNew && $anime->id !== null) {
                        $typeReports[] = ['animeId' => $anime->id, 'type' => $anime->getType(), 'sourceType' => $item->type];
                    }
                } catch (ReauthRequiredException $exception) {
                    // Not this item's problem to isolate — the outer catch below stops the whole
                    // run for it, same as before this per-item isolation existed.
                    throw $exception;
                } catch (\Throwable $exception) {
                    // Per-item isolation (issue #859): one item's failure (fillNewFrom(),
                    // reconcilePulledItem(), or a per-item flush() above) must not take the rest
                    // of this run's list down with it. $presentExternalIds was already set for
                    // this item above, so DeletedFromSourceDetector never flags it as removed
                    // just because it was skipped here.
                    $this->logger->warning('Skipping a pull item that failed during processing; the plugin\'s own list is unaffected and later items are still applied.', [
                        'pluginId' => (string) $pluginId,
                        'externalId' => $item->externalId,
                        'exception' => $exception,
                    ]);

                    $activeEntityManager = $recoveryEntityManager ?? $this->entityManager;
                    if (!$activeEntityManager->isOpen()) {
                        // Doctrine already closed it as its own reaction to the failed flush
                        // above (same reaction ExternalIdAlreadyClaimedException's handling
                        // above works around) — every write for the rest of this run, starting
                        // with the very next item, must go through a fresh EntityManager sharing
                        // the same DBAL connection instead.
                        $recoveryEntityManager = $this->openRecoveryEntityManager();
                    } elseif ($anime !== null && $activeEntityManager->contains($anime)) {
                        // Discard whatever this item's reconcilePulledItem() applied in memory
                        // (e.g. Anime::applyWatchProgress()) before failing — without this, the
                        // next flush() in this run (another item's, or the one after this loop)
                        // would durably commit this item's half-applied state.
                        $activeEntityManager->refresh($anime);
                    }
                }
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

            return false;
        }

        if ($recoveryEntityManager === null) {
            $this->entityManager->flush();
        } else {
            // Both detectors below are wired to this run's *original* EntityManager (through
            // SyncReviewItemRepository). Running them here would either see a stale identity map
            // (the create-conflict race, which only clear()s the original) or raise
            // EntityManagerClosedException outright (the per-item isolation path, which genuinely
            // closes it — see the class docblock's "Per-item isolation" section). Skip them for
            // this run and log it either way: any duplicate/disappeared item they would have
            // flagged is still present next pull (a newly created row keeps its external_id, so
            // it is not "new" again) — same self-healing stance already taken for a second
            // conflict earlier in this method.
            $this->logger->warning('Skipping post-pull duplicate/deletion review for this run: an earlier failure already switched this pull to a recovery EntityManager.', [
                'pluginId' => (string) $pluginId,
            ]);

            // The create-conflict race (issue #297) leaves $this->entityManager merely cleared,
            // not closed — a caller reusing it after this pull() returns gets a clean slate, so
            // `true` is accurate. Per-item isolation (issue #859) can genuinely close it (a real
            // Doctrine flush failure always does), and a caller must be told this run did not
            // fully succeed rather than being handed a shared EntityManager it cannot use —
            // reusing the same `false` this method already returns for a dead OAuth session,
            // rather than silently claiming success over a now-unusable EntityManager.
            return $this->entityManager->isOpen();
        }

        foreach ($newlyCreated as $anime) {
            $this->duplicateDetector->detect($anime);
        }

        // Records this plugin synced before but that are no longer in its list — never deleted
        // automatically, flagged for review (issue #217). Newly created items are keyed in
        // $byExternalId and are present in $presentExternalIds, so they never count as removed.
        // Narrowed to records that actually have DeletedFromSourceDetector::hasConfirmedListMembership()
        // for $pluginId (issue #863): $byExternalId is built from every cached external_id, and a
        // filler, bulk-fill, or scan can cache one without the source ever having listed the title
        // as a sync item, so a cached id alone proves nothing about absence from the source's
        // list — only a prior pull/push reconciliation's snapshot row, or an unresolved
        // first-contact-divergence review item naming $pluginId as origin (issue #861's pending
        // state, where that reconciliation deliberately withholds the snapshot row — see
        // hasConfirmedListMembership()'s own docblock), does.
        $disappeared = array_filter(
            array_diff_key($byExternalId, $presentExternalIds),
            fn (Anime $anime): bool => $this->deletionDetector->hasConfirmedListMembership($anime, (string) $pluginId),
        );
        $this->deletionDetector->detect($pluginId, $disappeared);

        $this->typeMismatchDetector->detect($pluginId, $typeReports);

        return true;
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
