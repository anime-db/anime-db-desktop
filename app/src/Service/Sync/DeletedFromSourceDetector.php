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

namespace App\Service\Sync;

use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\SyncRegistry;

/**
 * Source-side removal handling for the pull direction (issue #217): a title that this plugin
 * previously synced but that has disappeared from the user's list on the source is **never
 * deleted automatically** — it is flagged into the review store ({@see SyncReviewService}) for
 * the user to decide, same batch "requires attention" surface as {@see CrossVendorDuplicateDetector}.
 *
 * Two rules:
 * - **Hard storage protection.** A record linked to local files ({@see Anime::getStorage()}) is
 *   left completely alone — it never even becomes a review item. "Gone from the tracker list" is
 *   not "delete the downloaded copy".
 * - Otherwise it is flagged as {@see SyncReviewItemKind::DeletedFromSource} when nothing else
 *   holds it, or {@see SyncReviewItemKind::DeletionConflict} when the record is still linked to
 *   another *active* sync plugin (`still_present_on`) — deleted on A but maybe still on B.
 */
final class DeletedFromSourceDetector
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly SyncReviewService $reviewService,
    ) {
    }

    /**
     * $disappeared holds the local records this plugin synced before but that are absent from its
     * current pull() list. It is keyed by external_id — numeric ids become int keys, so the key
     * type is left open; only the values are used.
     *
     * @param array<array-key, Anime> $disappeared
     */
    public function detect(PluginId $pluginId, array $disappeared): void
    {
        if ($disappeared === []) {
            return;
        }

        // pull() runs periodically, and a flagged removal keeps the local record (and its
        // metadata link) untouched, so the same record would re-appear in $disappeared on every
        // later run. Skip anything already flagged for this plugin and still unresolved, so a
        // periodic pull does not pile up duplicate review items.
        $alreadyFlagged = $this->alreadyFlaggedFor($pluginId);

        foreach ($disappeared as $anime) {
            // Hard protection: a storage-backed record is never touched and never listed.
            if ($anime->getStorage() !== null) {
                continue;
            }

            $id = $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');
            if (isset($alreadyFlagged[$id])) {
                continue;
            }

            $stillPresentOn = $this->stillPresentOn($anime, $pluginId);

            $payload = ['anime_id' => $id, 'deleted_from' => (string) $pluginId];

            if ($stillPresentOn === []) {
                $this->reviewService->create(SyncReviewItemKind::DeletedFromSource, $payload);
            } else {
                $payload['still_present_on'] = $stillPresentOn;
                $this->reviewService->create(SyncReviewItemKind::DeletionConflict, $payload);
            }
        }
    }

    /**
     * Anime ids that already have an unresolved removal/conflict item raised for this plugin.
     *
     * @return array<int, true>
     */
    private function alreadyFlaggedFor(PluginId $pluginId): array
    {
        $flagged = [];
        foreach ($this->reviewService->findUnresolved() as $item) {
            $animeId = $item->payload['anime_id'] ?? null;

            if (\in_array($item->kind, [SyncReviewItemKind::DeletedFromSource, SyncReviewItemKind::DeletionConflict], true)
                && ($item->payload['deleted_from'] ?? null) === (string) $pluginId
                && \is_int($animeId)
            ) {
                $flagged[$animeId] = true;
            }
        }

        return $flagged;
    }

    /**
     * Other **active** sync plugins this record is still linked to (has a cached external_id for).
     * The record's own last-update time on the source is deliberately not consulted — an
     * abandoned tracker is not evidence the user changed their mind, so only the presence of a
     * live cross-source link makes it a conflict rather than a plain removal.
     *
     * @return list<string>
     */
    private function stillPresentOn(Anime $anime, PluginId $deletedFrom): array
    {
        $linkedPluginIds = array_map(strval(...), $anime->getExternalIdPluginIds());

        $result = [];
        foreach ($this->syncRegistry->allActive() as $otherPluginId => $sync) {
            if ($otherPluginId !== (string) $deletedFrom && \in_array($otherPluginId, $linkedPluginIds, true)) {
                $result[] = $otherPluginId;
            }
        }

        return $result;
    }
}
