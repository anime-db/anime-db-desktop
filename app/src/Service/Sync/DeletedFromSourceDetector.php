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
     * @param array<array-key, Anime> $disappeared local records this plugin synced before but
     *                                              that are absent from its current pull() list
     *                                              (keyed by external_id — numeric ids become int
     *                                              keys, so the key type is left open; only the
     *                                              values are used)
     */
    public function detect(PluginId $pluginId, array $disappeared): void
    {
        foreach ($disappeared as $anime) {
            // Hard protection: a storage-backed record is never touched and never listed.
            if ($anime->getStorage() !== null) {
                continue;
            }

            $id = $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');
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
     * Other **active** sync plugins this record is still linked to (has a cached external_id for).
     * The record's own last-update time on the source is deliberately not consulted — an
     * abandoned tracker is not evidence the user changed their mind, so only the presence of a
     * live cross-source link makes it a conflict rather than a plain removal.
     *
     * @return list<string>
     */
    private function stillPresentOn(Anime $anime, PluginId $deletedFrom): array
    {
        $externalIds = ($anime->getMetadata() ?? [])['external_id'] ?? [];

        $result = [];
        foreach ($this->syncRegistry->allActive() as $otherPluginId => $sync) {
            if ($otherPluginId !== (string) $deletedFrom && \array_key_exists($otherPluginId, $externalIds)) {
                $result[] = $otherPluginId;
            }
        }

        return $result;
    }
}
