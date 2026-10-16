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
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\ExternalIdResolver;
use App\Service\Plugin\FillerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Saturation of a record from the source links it already has (issue #1049).
 *
 * The run goes in passes over {@see FillerRegistry::findAllActive()}; within a pass, per filler:
 *  - a row with filled_at set is skipped (unless $force);
 *  - a row with filled_at NULL (a seeded or lazily resolved id) is filled by the id of the row;
 *  - no row: the id comes from {@see ExternalIdResolver::resolve()} over the record's links, and a
 *    filler that recognises nothing is skipped without a request.
 * A filler is called at most once per run, whatever its outcome. The next pass starts only if the
 * previous one made at least one call, so the run stops on the first pass without calls — which is
 * also how a chain (a link added by one filler enabling the next) is followed to its end.
 *
 * Nothing but still-empty fields is written and no title search happens: see
 * {@see BulkFillerService::fillExistingFromPlugin()}. A filler that throws is logged and counted as
 * called; a closed EntityManager aborts the run, since nothing can be flushed any more.
 *
 * With $descriptionsOnly (issue #1070) the run is the description recalculation instead: the
 * plugins come from {@see DescriptionRecalculationPlanner} (ranked ones that can still improve on
 * a stored row, filled_at ignored), each is called once in priority order, and only its
 * descriptions are written, through the usual write policy. filled_at and every other field stay.
 */
final class SaturationService
{
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly ExternalIdResolver $externalIdResolver,
        private readonly BulkFillerService $bulkFiller,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly DescriptionRecalculationPlanner $planner,
    ) {
    }

    /**
     * @param ?\Closure(): void $afterCall invoked after every plugin call (the lock heartbeat)
     *
     * @return int the number of plugin calls made
     */
    public function saturate(Anime $anime, bool $force = false, ?\Closure $afterCall = null, bool $descriptionsOnly = false): int
    {
        if ($descriptionsOnly) {
            return $this->recalculateDescriptions($anime, $afterCall);
        }

        $called = [];

        do {
            $calls = 0;
            foreach ($this->fillerRegistry->findAllActive() as $rawId => $filler) {
                $rawId = (string) $rawId;
                if (isset($called[$rawId])) {
                    continue;
                }
                if (!$this->entityManager->isOpen()) {
                    $this->logger->warning('EntityManager is closed, aborting saturation of anime #{animeId}.', ['animeId' => $anime->id]);

                    return \count($called);
                }

                $pluginId = new PluginId($rawId);
                $entry = $anime->getExternalIdEntry($pluginId);
                if ($entry !== null) {
                    if (!$force && $entry->getFilledAt() !== null) {
                        continue;
                    }
                    $externalId = $entry->externalId;
                } else {
                    $externalId = $this->externalIdResolver->resolve($anime, $pluginId, $filler);
                    if ($externalId === null) {
                        continue;
                    }
                }

                $called[$rawId] = true;
                ++$calls;
                try {
                    $this->bulkFiller->fillExistingFromPlugin($anime, $pluginId, $externalId);
                } catch (\Throwable $e) {
                    $this->logger->warning('Saturation of anime #{animeId} from plugin {pluginId} failed.', [
                        'animeId' => $anime->id,
                        'pluginId' => $rawId,
                        'exception' => $e,
                    ]);
                }
                if ($afterCall !== null) {
                    $afterCall();
                }
            }
        } while ($calls > 0);

        $this->logger->info('Saturation of anime #{animeId} finished after {calls} plugin call(s).', ['animeId' => $anime->id, 'calls' => \count($called)]);

        return \count($called);
    }

    /** @param ?\Closure(): void $afterCall */
    private function recalculateDescriptions(Anime $anime, ?\Closure $afterCall): int
    {
        $calls = 0;
        foreach ($this->planner->plan($anime) as $item) {
            if (!$this->entityManager->isOpen()) {
                $this->logger->warning('EntityManager is closed, aborting description recalculation of anime #{animeId}.', ['animeId' => $anime->id]);

                return $calls;
            }
            ++$calls;
            try {
                $this->bulkFiller->fillDescriptionsFromPlugin($anime, $item['pluginId'], $item['externalId']);
            } catch (\Throwable $e) {
                $this->logger->warning('Description recalculation of anime #{animeId} from plugin {pluginId} failed.', [
                    'animeId' => $anime->id,
                    'pluginId' => (string) $item['pluginId'],
                    'exception' => $e,
                ]);
            }
            if ($afterCall !== null) {
                $afterCall();
            }
        }

        $this->logger->info('Description recalculation of anime #{animeId} finished after {calls} plugin call(s).', ['animeId' => $anime->id, 'calls' => $calls]);

        return $calls;
    }
}
