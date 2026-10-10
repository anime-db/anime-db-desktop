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

namespace App\Service\Storage\Search;

use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\DefaultSearchPluginRegistry;
use App\Service\Plugin\FillerActiveTrait;
use App\Service\Plugin\PluginsConfigStore;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tries registered {@see SearchByPluginInterface} implementations in order and stops at the
 * first one that reports anything at all — the remaining implementations are never consulted,
 * so callers must not assume a "better" match further down the chain would have been considered.
 * That first non-empty list is returned as-is, ambiguity and all: this chain only picks which
 * plugin to trust, it does not resolve how many candidates that plugin found. The chain is empty
 * until a search or filler plugin is installed, in which case find() returns [] straight away;
 * Stage 4 plugins join by implementing the interface, with no change needed here or in the
 * storage scan that calls this service.
 *
 * `SearchByPluginInterface` lives in the read-only `anime-db/plugin-contracts` package, so it
 * cannot carry `#[AutoconfigureTag]` itself — it is tagged 'app.search_by_plugin' at compile time
 * instead, by {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass}
 * (issue #278, same pattern as {@see \App\Service\Plugin\FillerRegistry}'s `app.filler`).
 * `FillerInterface` extends `SearchByPluginInterface`, so any installed Filler plugin is picked
 * up by this chain too, without a separate registration — and its candidates already carry the
 * `externalId` needed for a bulk fill-in (issue #233), unlike the old local candidate type this
 * replaced.
 *
 * Storage-scan search only exists to feed a bulk-fill (see {@see \App\Service\Plugin\Filler\BulkFillerService}),
 * so a plugin whose filler is disabled (`features.filler = false`) is skipped here too, via
 * {@see FillerActiveTrait} — the same check {@see \App\Service\Plugin\FillerRegistry} uses to
 * gate the fill itself. Without this, a disabled-filler plugin would still find a candidate here,
 * only for the fill to then be refused, leaving a title-only placeholder behind (issue #280). A
 * "pure" search plugin with no filler toggle at all (implements only `SearchByPluginInterface`)
 * has nothing to gate on and stays active — see {@see FillerActiveTrait} for that default.
 *
 * The order is registration order, except that the plugin the user explicitly picked as the default
 * search plugin is consulted first (issue #1019); still only the first non-empty answer wins.
 */
final class SearchByPluginChain
{
    use FillerActiveTrait;

    /** @param iterable<string, SearchByPluginInterface> $plugins keyed by plugin id */
    public function __construct(
        #[AutowireIterator('app.search_by_plugin', indexAttribute: 'id')]
        private readonly iterable $plugins,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly DefaultSearchPluginRegistry $defaultSearch,
    ) {
    }

    /** @return list<SearchByPluginCandidate> */
    public function find(string $name): array
    {
        foreach ($this->orderedActivePlugins() as $plugin) {
            $candidates = $plugin->find($name);

            if ($candidates !== []) {
                // No array_values() here: since anime-db/plugin-contracts v0.17.0 find() promises
                // list<SearchByPluginCandidate>, which is what this chain's own contract needs
                // (mergeCandidates()'s array_map() over ScanCandidate::fromOrphan() relies on
                // integer-indexed lists throughout). Before that the contract only guaranteed
                // SearchByPluginCandidate[], and this line paid for the difference.
                return $candidates;
            }
        }

        return [];
    }

    /**
     * Active plugins in registration order, except that the plugin the user explicitly chose as
     * the default search plugin goes first (issue #1019). The choice comes from
     * {@see DefaultSearchPluginRegistry::selected()}; a choice that is not an active plugin right
     * now is null there and changes nothing.
     *
     * @return list<SearchByPluginInterface>
     */
    private function orderedActivePlugins(): array
    {
        $preferredId = $this->defaultSearch->selected();
        $preferred = null;
        $rest = [];

        foreach ($this->plugins as $id => $plugin) {
            if (!$this->isFillerActive(new PluginId((string) $id))) {
                continue;
            }

            if ($preferredId !== null && (string) $preferredId === (string) $id) {
                $preferred = $plugin;

                continue;
            }

            $rest[] = $plugin;
        }

        return $preferred !== null ? [$preferred, ...$rest] : $rest;
    }
}
