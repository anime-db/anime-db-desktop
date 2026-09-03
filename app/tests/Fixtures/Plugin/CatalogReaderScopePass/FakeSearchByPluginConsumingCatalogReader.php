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

namespace AnimeDb\Plugins\FakeVendor;

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;

/**
 * Fixture used by CatalogReaderScopePassTest to stand in for a plugin's own
 * SearchByPlugin/Filler/Sync service that *also* type-hints {@see CatalogReaderInterface} in its
 * constructor — a filler naturally wants to read the record it is about to fill. The pass under
 * test must never pick this service as its own plugin's resolver: doing so would make the
 * CatalogReader instance injected into it depend on this very service, a circular reference only
 * caught by Symfony's CheckCircularReferencesPass at container compile time, not by inspecting
 * bindings alone.
 */
final class FakeSearchByPluginConsumingCatalogReader implements SearchByPluginInterface
{
    public function __construct(
        public readonly CatalogReaderInterface $catalogReader,
    ) {
    }

    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    /** @return SearchByPluginCandidate[] */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        return [];
    }
}
