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

use AnimeDb\PluginContracts\AnimeId;

/**
 * App-local stand-in for `AnimeDb\PluginContracts\PluginDataStoreInterface` (contracts issue
 * #30), which is merged to the contracts package's default branch but not yet in a tagged
 * release this app's composer.json can require (latest tag is still v0.6.0). The method shapes
 * match the contract exactly, so swapping `implements PluginDataStoreInterface` for the
 * contracts one is a one-line change once v0.7.0 is tagged and required — nothing about
 * {@see PluginDataStore} itself needs to change.
 *
 * An instance is scoped to a single plugin (see {@see PluginDataStore}'s constructor and
 * {@see DependencyInjection\Compiler\PluginDataStoreScopePass}, which binds one per plugin), so
 * neither method takes a {@see \App\Entity\ValueObject\PluginId} — the instance already knows
 * its own, and a plugin can never reach another plugin's slice through this interface.
 */
interface PluginDataStoreInterface
{
    /**
     * Reads this plugin's previously stored payload for the given anime. Empty array if nothing
     * has been stored yet.
     *
     * @return array<string, mixed>
     */
    public function read(AnimeId $anime): array;

    /**
     * Merges $data into this plugin's stored payload for the given anime and persists it. A
     * merge, not a replace: keys already stored from a previous write() that $data does not
     * mention are kept as-is.
     *
     * @param array<string, mixed> $data
     */
    public function write(AnimeId $anime, array $data): void;
}
