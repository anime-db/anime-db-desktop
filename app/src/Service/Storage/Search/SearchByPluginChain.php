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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tries registered {@see SearchByPluginInterface} implementations in order and stops at the
 * first match — the remaining implementations are never consulted, so callers must not assume
 * a "better" match further down the chain would have been considered. Currently resolves to a
 * chain of one ({@see NullSearchByPlugin}); Stage 4 plugins join by implementing the interface,
 * with no change needed here or in the storage scan that calls this service.
 */
final class SearchByPluginChain
{
    /** @param iterable<SearchByPluginInterface> $plugins */
    public function __construct(
        #[AutowireIterator('app.search_by_plugin')]
        private readonly iterable $plugins,
    ) {
    }

    public function find(string $name): ?SearchByPluginCandidate
    {
        foreach ($this->plugins as $plugin) {
            $candidate = $plugin->find($name);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }
}
