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

namespace App\Service\Storage\Search;

use AnimeDb\PluginContracts\SearchByPluginCandidate;
use AnimeDb\PluginContracts\SearchByPluginInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tries registered {@see SearchByPluginInterface} implementations in order and stops at the
 * first one that reports anything at all — the remaining implementations are never consulted,
 * so callers must not assume a "better" match further down the chain would have been considered.
 * That first non-empty list is returned as-is, ambiguity and all: this chain only picks which
 * plugin to trust, it does not resolve how many candidates that plugin found. Currently resolves
 * to a chain of one ({@see NullSearchByPlugin}); Stage 4 plugins join by implementing the
 * interface, with no change needed here or in the storage scan that calls this service.
 *
 * `SearchByPluginInterface` lives in the read-only `anime-db/plugin-contracts` package, so it
 * cannot carry `#[AutoconfigureTag]` itself — it is tagged 'app.search_by_plugin' at compile time
 * instead, by {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass}
 * (issue #278, same pattern as {@see \App\Service\Plugin\FillerRegistry}'s `app.filler`).
 * `FillerInterface` extends `SearchByPluginInterface`, so any installed Filler plugin is picked
 * up by this chain too, without a separate registration — and its candidates already carry the
 * `externalId` needed for a bulk fill-in (issue #233), unlike the old local candidate type this
 * replaced.
 */
final class SearchByPluginChain
{
    /** @param iterable<SearchByPluginInterface> $plugins */
    public function __construct(
        #[AutowireIterator('app.search_by_plugin')]
        private readonly iterable $plugins,
    ) {
    }

    /** @return list<SearchByPluginCandidate> */
    public function find(string $name): array
    {
        foreach ($this->plugins as $plugin) {
            $candidates = $plugin->find($name);

            if ($candidates !== []) {
                // Contract's find() only guarantees SearchByPluginCandidate[], not a list — this
                // chain's own contract (mergeCandidates()'s array_map() over ScanCandidate::fromOrphan())
                // relies on integer-indexed lists throughout.
                return array_values($candidates);
            }
        }

        return [];
    }
}
