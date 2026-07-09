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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for Stage 4's plugin system (v1 equivalent: `Plugin\Fill\Search\SearchInterface`).
 * Looks up a cleaned storage item name against an external source and reports at most one match —
 * this is a "first match wins" contract, not a ranked collection. Every implementation is
 * auto-tagged for {@see SearchByPluginChain}, which is the only intended caller.
 */
#[AutoconfigureTag('app.search_by_plugin')]
interface SearchByPluginInterface
{
    public function find(string $name): ?SearchByPluginCandidate;
}
