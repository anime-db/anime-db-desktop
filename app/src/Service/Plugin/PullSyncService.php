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

use AnimeDb\PluginContracts\SyncInterface;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
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
 * stale id) is folded onto it instead of duplicated. Only when no match exists is a
 * brand-new title placeholder created, the same title-only pattern
 * ScanStorageService/BulkFillerService use when the plugin doesn't resolve to something
 * richer: a TvAnime, since SyncItem carries no anime type to pick a more specific subclass
 * from — the placeholder is also added to the in-memory map, so a source that repeats the
 * same external id within one pull() list folds onto it too instead of duplicating.
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
    ) {
    }

    public function pull(PluginId $pluginId, SyncInterface $sync): void
    {
        $byExternalId = $this->animeRepository->indexByExternalId($pluginId);

        foreach ($sync->pull() as $item) {
            $anime = $byExternalId[$item->externalId] ?? null;

            if ($anime === null) {
                // WatchStatus has no default (see Anime::$watchStatus) — Plan is the same
                // placeholder starting point ScanStorageService uses, overwritten below by
                // the source's actual status unless that throws (see catch below).
                $anime = (new TvAnime())->setTitle($item->title)->setWatchStatus(WatchStatus::Plan);
                $anime->rememberExternalId($pluginId, $item->externalId);
                $this->entityManager->persist($anime);
                $byExternalId[$item->externalId] = $anime;
            }

            try {
                $anime->setWatchStatus(WatchStatusMapper::toWatchStatus($item->status));
            } catch (InvalidWatchStatusException) {
                // The source considers the title completed, but this Anime's own production
                // status (from datePremiere/dateEnd) is Ongoing — it's genuinely airing right
                // now locally, so it can't already be fully watched. Same invariant
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
