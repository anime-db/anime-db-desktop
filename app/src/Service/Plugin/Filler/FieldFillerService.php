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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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
    /**
     * A handful of minutes: long enough to dedupe repeated findById() calls across several
     * field-fill clicks in the same edit session, short enough that "fill from source" still
     * means reasonably fresh data rather than an indefinitely stale snapshot.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly PluginAnimeDataMerger $merger,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
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

        return $this->findById($filler, $pluginId, $externalId);
    }

    /**
     * Cache key is derived, not "pluginId:externalId" literally as the issue describes it:
     * Symfony's cache keys reject `{}()/\@:` outright, and a third-party plugin's external id
     * is free-form text a host cannot constrain.
     */
    private function findById(FillerInterface $filler, PluginId $pluginId, string $externalId): ?PluginAnimeData
    {
        $key = 'filler.'.$pluginId.'.'.hash('xxh128', $externalId);

        return $this->cache->get($key, static function (ItemInterface $item, bool &$save) use ($filler, $externalId): ?PluginAnimeData {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $data = $filler->findById($externalId);
            // A transient "not found" (no match yet, source not queried) must not stick around
            // for CACHE_TTL_SECONDS - only a real result is worth caching.
            $save = $data !== null;

            return $data;
        });
    }
}
