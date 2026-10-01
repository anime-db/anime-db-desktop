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

namespace App\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\FillerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Point fill-in scenario (issue #234): a single field on an already-persisted Anime is filled
 * from one explicitly chosen plugin, as opposed to {@see BulkFillerService}'s create-and-fill-
 * everything path. The resolve chain itself is the same shape as BulkFillerService's own
 * resolve()/findById() (find() -> first candidate -> findById()), just entered differently:
 * {@see Anime::getExternalId()} is tried first, since an already-persisted anime usually
 * already has a source URL a plugin can resolve an id from without a find() round trip at all.
 *
 * A plugin's find()/findById()/resolveExternalId() throwing, or returning nothing, is treated
 * the same way as BulkFillerService treats it: caught and turned into a "not found" result, not
 * an error page - the caller renders that back as a soft inline notice.
 */
final class FieldFillerService
{
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly PluginAnimeDataMerger $merger,
        private readonly EntityManagerInterface $entityManager,
        private readonly CachedFillerLookup $lookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return FillResult Applied when $field was applied to $anime and the change flushed;
     *                    NotFound when $pluginId is not an active filler for $field, the plugin
     *                    could not resolve anything, or a plugin call threw; ImageRejected when
     *                    the plugin did return data for $field but
     *                    {@see PluginAnimeDataMerger::apply()} could not apply it (today only
     *                    possible for 'cover'/'images', see that method's docblock)
     */
    public function fill(Anime $anime, PluginId $pluginId, string $field): FillResult
    {
        $filler = $this->fillerRegistry->findByPluginId($pluginId);
        if ($filler === null || !\in_array($field, $filler->getFillableFields(), true)) {
            return FillResult::NotFound;
        }

        try {
            $data = $this->resolve($filler, $pluginId, $anime);
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin find()/findById() failed while filling a single field, leaving it unchanged.', [
                'pluginId' => (string) $pluginId,
                'field' => $field,
                'exception' => $e,
            ]);

            return FillResult::NotFound;
        }

        if ($data === null) {
            return FillResult::NotFound;
        }

        $unapplied = $this->merger->apply($anime, $data, [$field]);
        $this->entityManager->flush();

        return $unapplied === [] ? FillResult::Applied : FillResult::ImageRejected;
    }

    private function resolve(FillerInterface $filler, PluginId $pluginId, Anime $anime): ?PluginAnimeData
    {
        $externalId = $anime->getExternalId($pluginId, $filler);

        if ($externalId === null) {
            $candidates = $filler->find($anime->getTitle());
            if ($candidates === []) {
                return null;
            }

            $externalId = $candidates[0]->getExternalId();
            $anime->rememberExternalId($pluginId, $externalId);
        }

        return $this->lookup->findById($filler, $pluginId, $externalId);
    }
}
