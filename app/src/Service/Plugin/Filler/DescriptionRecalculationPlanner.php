<?php

declare(strict_types=1);

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

namespace App\Service\Plugin\Filler;

use App\Entity\Anime;
use App\Entity\AnimeDescription;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\ExternalIdResolver;
use App\Service\Plugin\FillerRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one rule of the description recalculation (issue #1070), shared by the run itself and by the
 * "records to check" estimate. A ranked plugin P is called for a record when the record has at
 * least one description row whose source is not `manual` and is either NULL or ranked below P (an
 * unranked source ranks below every ranked one). The plugin must be an active filler that can fill
 * 'descriptions'; its filled_at marker does not matter. Its external id is the one stored in
 * anime_external_id or the one {@see ExternalIdResolver::resolve()} derives from the record's
 * links - there is no title search; a plugin without an id is not called.
 */
class DescriptionRecalculationPlanner
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly ExternalIdResolver $externalIdResolver,
        private readonly AppSettingsProvider $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The plugins to call for $anime, in priority order.
     *
     * @return list<array{pluginId: PluginId, externalId: string}>
     */
    public function plan(Anime $anime): array
    {
        $priority = $this->settings->getDescriptionSourcePriority();
        if ($priority === []) {
            return [];
        }

        $fillers = $this->fillerRegistry->findWithIdByField('descriptions');
        $plan = [];
        foreach ($priority as $rawId) {
            if (!isset($fillers[$rawId]) || !$this->hasLowerRow($anime, $rawId, $priority)) {
                continue;
            }

            $pluginId = new PluginId($rawId);
            $externalId = $anime->getCachedExternalId($pluginId) ?? $this->externalIdResolver->resolve($anime, $pluginId, $fillers[$rawId]);
            if ($externalId !== null) {
                $plan[] = ['pluginId' => $pluginId, 'externalId' => $externalId];
            }
        }

        return $plan;
    }

    /**
     * Ids of the records the recalculation would call at least one plugin for.
     *
     * @return list<int>
     */
    public function eligibleAnimeIds(): array
    {
        $priority = $this->settings->getDescriptionSourcePriority();
        if ($priority === []) {
            return [];
        }

        // Nothing is ranked above the first plugin, so only rows of other sources can be below it.
        /** @var list<int|string> $candidates */
        $candidates = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT anime_id FROM anime_description WHERE source IS NULL OR (source != ? AND source != ?) ORDER BY anime_id',
            [AnimeDescription::SOURCE_MANUAL, $priority[0]],
        );
        $candidates = array_map('intval', $candidates);

        $eligible = [];
        foreach (array_chunk($candidates, self::CHUNK_SIZE) as $chunk) {
            $records = $this->entityManager->getRepository(Anime::class)->findBy(['id' => $chunk]);
            foreach ($records as $anime) {
                if ($anime->id !== null && $this->plan($anime) !== []) {
                    $eligible[] = $anime->id;
                }
                // plan() may cache a link-derived id in memory only; the estimate must not persist it.
                $this->entityManager->detach($anime);
            }
        }

        return $eligible;
    }

    /** @param list<string> $priority */
    private function hasLowerRow(Anime $anime, string $pluginId, array $priority): bool
    {
        $rank = array_search($pluginId, $priority, true);
        if ($rank === false) {
            return false;
        }

        foreach ($anime->getDescriptions() as $row) {
            if ($row->source === AnimeDescription::SOURCE_MANUAL) {
                continue;
            }
            if ($row->source === null) {
                return true;
            }
            $rowRank = array_search($row->source, $priority, true);
            if ($rowRank === false || $rowRank > $rank) {
                return true;
            }
        }

        return false;
    }
}
