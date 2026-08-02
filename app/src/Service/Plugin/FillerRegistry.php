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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Lists installed, active {@see FillerInterface} plugins for two callers: the per-field
 * "fill in from source" dropdown on the anime edit form (several plugins may support the same
 * field) and the bulk-fill flow, which already knows which plugin to use (chosen by the
 * storage scan or by the user) and just needs that one instance, not a scan of all of them.
 *
 * `FillerInterface` lives in the read-only `anime-db/plugin-contracts` package, so it cannot
 * carry `#[AutoconfigureTag]` the way the local {@see \App\Service\Storage\Search\SearchByPluginInterface}
 * does — it is tagged 'app.filler' at compile time instead, by
 * {@see DependencyInjection\Compiler\TagPluginServicesPass} (issue #278).
 *
 * `indexAttribute: 'id'` keys the injected iterable by each filler's own {@see PluginId}: the
 * compiler pass derives it from the plugin's namespace and puts it on the tag as the `id`
 * attribute, matching the same string {@see PluginsConfigStore} already keys `plugins.json` by.
 * Neither `ExternalIdResolutionInterface` nor `FillerInterface` exposes a way to ask an arbitrary
 * instance for its own `PluginId` directly — the closest thing, `resolveExternalId()`, resolves an
 * id on an external source from catalog URLs, not the plugin's own identity.
 *
 * The `features.filler ?? true` activity check itself lives in {@see FillerActiveTrait}, shared
 * with {@see \App\Service\Storage\Search\SearchByPluginChain} (issue #280).
 */
final class FillerRegistry
{
    use FillerActiveTrait;

    /** @param iterable<string, FillerInterface> $fillers */
    public function __construct(
        #[AutowireIterator('app.filler', indexAttribute: 'id')]
        private readonly iterable $fillers,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    /**
     * @return list<FillerInterface> active plugins that report $field among getFillableFields()
     */
    public function findByField(string $field): array
    {
        $result = [];
        foreach ($this->fillers as $id => $filler) {
            if ($this->isFillerActive(new PluginId((string) $id)) && \in_array($field, $filler->getFillableFields(), true)) {
                $result[] = $filler;
            }
        }

        return $result;
    }

    /**
     * Resolves a single, already-known plugin without the caller having to enumerate every
     * registered filler itself. Returns null both when no filler is registered under this id
     * and when the matching plugin is installed but disabled (features.filler false).
     */
    public function findByPluginId(PluginId $pluginId): ?FillerInterface
    {
        $filler = iterator_to_array($this->fillers)[(string) $pluginId] ?? null;

        return $filler !== null && $this->isFillerActive($pluginId) ? $filler : null;
    }
}
