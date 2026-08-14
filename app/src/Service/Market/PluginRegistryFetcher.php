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

use App\Service\Market\Exception\PluginRegistryFetchException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads `plugins-registry.json` and its detached `.sig` signature from a fixed, hardcoded
 * list of mirrors, trying each in order and falling back to the next on any failure. The list is
 * intentionally not read from anywhere dynamic (env, config file, the registry itself): the
 * registry's mirrors cannot be sourced from the registry, since that is the very file being
 * fetched (chicken-and-egg), and this project does not need remotely updatable mirrors (see
 * `.claude-docs` / issue #292 — freshness and dynamic mirror lists were deliberately dropped).
 *
 * The maintainer's own mirror is tried first, GitHub second — matching the project's "one
 * self-hosted mirror + GitHub as fallback" distribution model. Both mirrors are treated as
 * equally untrusted transport: the downloaded bytes only become trustworthy once
 * {@see PluginRegistrySignatureVerifier} accepts their signature.
 */
final class PluginRegistryFetcher
{
    private const array REGISTRY_MIRROR_URLS = [
        'https://mr01.anime-db.org/plugins-registry.json',
        'https://raw.githubusercontent.com/anime-db/anime-db-plugins/master/plugins-registry.json',
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @throws PluginRegistryFetchException if every mirror failed to serve both files
     */
    public function fetch(): PluginRegistryDocument
    {
        $failuresByMirrorUrl = [];

        foreach (self::REGISTRY_MIRROR_URLS as $registryUrl) {
            try {
                $registryJson = $this->fetchBody($registryUrl);
                $signatureBase64 = $this->fetchBody($registryUrl.'.sig');
            } catch (\Throwable $exception) {
                $failuresByMirrorUrl[$registryUrl] = $exception->getMessage();
                continue;
            }

            return new PluginRegistryDocument($registryJson, trim($signatureBase64));
        }

        throw new PluginRegistryFetchException($failuresByMirrorUrl);
    }

    private function fetchBody(string $url): string
    {
        return $this->httpClient->request('GET', $url)->getContent();
    }
}
