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

/**
 * Orchestrates a trusted `plugins-registry.json` load: download from the configured mirrors
 * ({@see PluginRegistryFetcher}), verify its detached Ed25519 signature *before* looking at its
 * content at all ({@see PluginRegistrySignatureVerifier}), parse it ({@see PluginRegistry}), and
 * reject it as a rollback if its `sequence` is lower than the last one this app has ever
 * accepted ({@see PluginRegistryCache}).
 *
 * Any failure in that chain does not bubble up as an exception: the last cached, already-trusted
 * registry is returned instead (if one exists), packaged together with the failure so the caller
 * can still show the user an error (issue #292's accepted-cases table: "reject the registry,
 * keep the last valid one from cache, show an error").
 */
final class PluginRegistryLoader
{
    public function __construct(
        private readonly PluginRegistryFetcher $fetcher,
        private readonly PluginRegistrySignatureVerifier $signatureVerifier,
        private readonly PluginRegistryCache $cache,
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
            $registry = PluginRegistry::fromJson($document->registryJson);
        } catch (InvalidPluginRegistryContentException $exception) {
            return $this->fallbackToCache($exception);
        }

        $lastKnownSequence = $this->cache->getLastSequence();
        if ($lastKnownSequence !== null && $registry->sequence < $lastKnownSequence) {
            return $this->fallbackToCache(new PluginRegistryRollbackException($registry->sequence, $lastKnownSequence));
        }

        $this->cache->store($document->registryJson);

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
