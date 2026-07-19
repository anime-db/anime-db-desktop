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

use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\SyncInterface;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Core of the pull direction of sync (issue #257): applies one plugin's
 * SyncInterface::pull() list to the local catalog idempotently, so a periodic re-run of the
 * same plugin never duplicates an already-known title.
 *
 * AnimeRepository::indexByExternalId() builds the reverse lookup by metadata['external_id']
 * [$pluginId] once, up front (see that method's docblock for the accepted risk of scanning
 * the unindexed metadata JSON column) — resolving every pulled item against that in-memory
 * map, rather than repeating the scan per item, covers both cases the issue describes: an
 * already-synced item resolves to its known local Anime, and a "new" item that in fact
 * already has a local match (e.g. added by another plugin, or by a previous pull under a
 * stale id) is folded onto it instead of duplicated.
 *
 * A "new" item is never created as a bare title-only stub (issue #257 review): a dateless
 * placeholder can never legitimately reach Completed under Anime::setWatchStatus()'s
 * invariant, and a record carrying only a title is clutter that looks like a bug. Instead a
 * new item is created through the full BulkFillerService fill-in path, using the sync
 * plugin's own filler capability — SyncInterface is expected to extend FillerInterface once
 * anime-db-plugin-contracts#25 lands; until then this checks `$sync instanceof
 * FillerInterface` defensively, since the installed contract (^0.4) does not guarantee it
 * yet. BulkFillerService::fillNewFrom() is used rather than fillNewFromPlugin(): the external
 * id is already known from SyncItem, so there is no need to search by title, and going
 * through the sync plugin instance directly (instead of FillerRegistry::findByPluginId())
 * means enrichment does not depend on that plugin's unrelated features.filler toggle. If
 * $sync isn't a FillerInterface, or its findById() can't resolve the id, the item is skipped
 * entirely for this run — it will be created once the source data is actually available on a
 * later pull.
 *
 * Where/when this runs (periodic job, manual trigger, ...) is out of scope here — a future
 * caller is expected to invoke pull() once per SyncRegistry::allActive() entry, mirroring how
 * PushSyncMessageHandler consumes that same registry for the push direction (issue #214).
 * Cross-vendor dedup (issue #216) and source-side removal (issue #217) are separate concerns
 * layered on top of this.
 */
final class PullSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeRepository $animeRepository,
        private readonly BulkFillerService $bulkFillerService,
    ) {
    }

    public function pull(PluginId $pluginId, SyncInterface $sync): void
    {
        $byExternalId = $this->animeRepository->indexByExternalId($pluginId);

        foreach ($sync->pull() as $item) {
            $anime = $byExternalId[$item->externalId] ?? null;

            if ($anime === null) {
                if (!$sync instanceof FillerInterface) {
                    continue;
                }

                $anime = $this->bulkFillerService->fillNewFrom($sync, $pluginId, $item->externalId);
                if ($anime === null) {
                    continue;
                }

                $byExternalId[$item->externalId] = $anime;
            }

            try {
                $anime->setWatchStatus(WatchStatusMapper::toWatchStatus($item->status));
            } catch (InvalidWatchStatusException) {
                // The source considers the title completed, but this Anime's own production
                // status (from datePremiere/dateEnd) isn't Released — either it's genuinely
                // airing right now locally, or (for a title this same run just created) the
                // plugin's own fill-in data didn't carry release dates. Same invariant
                // AnimeEditableController::updateWatchStatus() enforces for a user-driven
                // edit; here there's no form to reject, so this single item's status is
                // skipped for this run rather than failing the whole pull. It resolves itself
                // once the local production status catches up (dateEnd gets filled in, or a
                // later pull once the source itself no longer reports it as completed).
            }
        }

        $this->entityManager->flush();
    }
}
