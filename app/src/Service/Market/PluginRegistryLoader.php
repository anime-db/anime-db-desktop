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

namespace App\Service\Market;

use App\Service\Market\Exception\InvalidPluginRegistryContentException;
use App\Service\Market\Exception\InvalidPluginRegistrySignatureException;
use App\Service\Market\Exception\PluginRegistryFetchException;
use App\Service\Market\Exception\PluginRegistryRollbackException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a trusted `plugins-registry.json` load: download from the configured mirrors
 * ({@see PluginRegistryFetcher}), verify its detached Ed25519 signature *before* looking at its
 * content at all ({@see PluginRegistrySignatureVerifier}), parse it ({@see PluginRegistry}), and
 * reject it as a rollback if its `sequence` is lower than the last one this app has ever
 * accepted ({@see PluginRegistryHighWaterMarkStore}).
 *
 * The comparison also floors against the currently cached registry's `sequence`
 * ({@see PluginRegistryCache::getCachedRegistry()}): an install that predates
 * PluginRegistryHighWaterMarkStore has no baseline recorded there yet, but its cache file already
 * holds the last registry it accepted, so that value still has to be honored on the first load
 * after upgrading — otherwise that one load would accept an older, replayed registry before the
 * new store gets a chance to persist a baseline.
 *
 * Any failure in that chain does not bubble up as an exception: the last cached, already-trusted
 * registry is returned instead (if one exists), packaged together with the failure so the caller
 * can still show the user an error (issue #292's accepted-cases table: "reject the registry,
 * keep the last valid one from cache, show an error"). This also covers a freshly accepted
 * registry that fails to persist to the cache ({@see PluginRegistryCache::store()}) or to raise
 * the high-water-mark ({@see PluginRegistryHighWaterMarkStore::raise()}): both writes are
 * best-effort, so the caller still gets the already-verified registry back instead of a crash.
 */
final class PluginRegistryLoader
{
    public function __construct(
        private readonly PluginRegistryFetcher $fetcher,
        private readonly PluginRegistrySignatureVerifier $signatureVerifier,
        private readonly PluginRegistryCache $cache,
        private readonly PluginRegistryHighWaterMarkStore $highWaterMark,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function load(): PluginRegistryLoadResult
    {
        try {
            $document = $this->fetcher->fetch();
        } catch (PluginRegistryFetchException $exception) {
            return $this->fallbackToCache($exception);
        }

        if (!$this->signatureVerifier->verify($document->registryJson, $document->signatureBase64)) {
            return $this->fallbackToCache(new InvalidPluginRegistrySignatureException(
                'plugins-registry.json signature is missing, malformed, or not from a trusted key.',
            ));
        }

        try {
            $registry = PluginRegistry::fromJson($document->registryJson, $this->logger);
        } catch (InvalidPluginRegistryContentException $exception) {
            return $this->fallbackToCache($exception);
        }

        $lastKnownSequence = $this->highWaterMark->getSequence();
        $cachedSequence = $this->cache->getCachedRegistry()?->sequence;
        if ($cachedSequence !== null && ($lastKnownSequence === null || $cachedSequence > $lastKnownSequence)) {
            $lastKnownSequence = $cachedSequence;
        }

        if ($lastKnownSequence !== null && $registry->sequence < $lastKnownSequence) {
            return $this->fallbackToCache(new PluginRegistryRollbackException($registry->sequence, $lastKnownSequence));
        }

        try {
            $this->cache->store($document->registryJson);
        } catch (\RuntimeException) {
            // Caching a fresh, already-verified registry is a best-effort side effect: if the
            // write fails (disk full, read-only directory, no permissions), the registry itself
            // is still valid and must be handed to the caller, not lost behind a crash.
        }

        try {
            $this->highWaterMark->raise($registry->sequence);
        } catch (\RuntimeException) {
            // Same best-effort rationale as the cache write above: a failure to persist the new
            // high-water-mark must not lose the already-verified registry. It only means the
            // anti-rollback baseline stays at its previous value until a later load succeeds.
        }

        return PluginRegistryLoadResult::fresh($registry);
    }

    private function fallbackToCache(\Throwable $reason): PluginRegistryLoadResult
    {
        $cached = $this->cache->getCachedRegistry();

        return $cached !== null
            ? PluginRegistryLoadResult::servedFromCache($cached, $reason)
            : PluginRegistryLoadResult::unavailable($reason);
    }
}
