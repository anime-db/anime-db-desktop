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

namespace App\Service\Sync;

use AnimeDb\PluginContracts\Model\AnimeType as ContractAnimeType;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\ValueObject\PluginId;

/**
 * Type divergence handling for the pull direction (issue #1002): a pull never changes the type of a
 * record, it only compares the type a source reports for a title with the type of the record and
 * flags a difference as {@see SyncReviewItemKind::TypeMismatch} for the user to decide.
 *
 * - One item per (record, plugin, source type). Resolved items count too, so "keep" is final for
 *   that source value; only a new value on the source raises a new item.
 * - A record whose type equals the source's closes the open items of that (record, plugin) pair.
 * - A source that reports no type (`null`) is not a difference and never reaches this class.
 */
final class TypeMismatchDetector
{
    public function __construct(private readonly SyncReviewService $reviewService)
    {
    }

    /**
     * @param list<array{animeId: int, type: AnimeType, sourceType: ContractAnimeType}> $reports one per pulled record
     *                                                                                           that already existed, with the type the record has and the one the source reports
     */
    public function detect(PluginId $pluginId, array $reports): void
    {
        if ($reports === []) {
            return;
        }

        /** @var array<int, array<string, true>> $known source types with an item (any status), by anime id */
        $known = [];
        /** @var array<int, list<\App\Entity\SyncReviewItem>> $open */
        $open = [];
        foreach ($this->reviewService->findAllByKind(SyncReviewItemKind::TypeMismatch) as $item) {
            $animeId = $item->payload['anime_id'] ?? null;
            if (!\is_int($animeId) || ($item->payload['plugin_id'] ?? null) !== (string) $pluginId) {
                continue;
            }

            $known[$animeId][(string) ($item->payload['source_type'] ?? '')] = true;
            if (!$item->isResolved()) {
                $open[$animeId][] = $item;
            }
        }

        foreach ($reports as $report) {
            $animeId = $report['animeId'];

            if ($report['type']->value === $report['sourceType']->value) {
                foreach ($open[$animeId] ?? [] as $item) {
                    $this->reviewService->resolve($item);
                }

                continue;
            }

            if (isset($known[$animeId][$report['sourceType']->value])) {
                continue;
            }

            $this->reviewService->create(SyncReviewItemKind::TypeMismatch, [
                'anime_id' => $animeId,
                'plugin_id' => (string) $pluginId,
                'source_type' => $report['sourceType']->value,
            ]);
        }
    }
}
