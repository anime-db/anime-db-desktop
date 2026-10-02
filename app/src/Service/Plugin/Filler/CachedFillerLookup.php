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
use App\Entity\ValueObject\PluginId;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Shared cached {@see FillerInterface::findById()} lookup (issue #832), replacing the
 * per-service-lifetime array cache {@see BulkFillerService} used to keep and the dedicated pool
 * cache {@see FieldFillerService} already had: both a bulk scan/confirm and a point fill-in can
 * ask the same plugin for the same external id within a short window, and now share one cache
 * instead of each keeping (or, for a Messenger consumer process living across many messages, never
 * resetting) its own.
 *
 * `null` and a thrown exception are deliberately never cached: a transient "nothing found yet"
 * (source not queried, not indexed yet) or a transport failure must not stick around for
 * {@see self::CACHE_TTL_SECONDS} — only a real result is worth caching.
 */
final class CachedFillerLookup
{
    /**
     * A handful of minutes: long enough to dedupe repeated findById() calls across a single
     * scan/confirm/field-fill operation, short enough that "fill from source" still means
     * reasonably fresh data rather than an indefinitely stale snapshot.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Cache key is derived, not "pluginId:externalId" literally: Symfony's cache keys reject
     * `{}()/\@:` outright, and a third-party plugin's external id is free-form text a host
     * cannot constrain.
     */
    public function findById(FillerInterface $filler, PluginId $pluginId, string $externalId): ?PluginAnimeData
    {
        $key = 'filler.'.$pluginId.'.'.hash('xxh128', $externalId);

        return $this->cache->get($key, static function (ItemInterface $item, bool &$save) use ($filler, $externalId): ?PluginAnimeData {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $data = $filler->findById($externalId);
            $save = $data !== null;

            return $data;
        });
    }
}
