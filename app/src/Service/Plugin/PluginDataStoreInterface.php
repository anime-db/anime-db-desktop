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

use AnimeDb\PluginContracts\Model\AnimeId;

/**
 * App-local stand-in for `AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface` (contracts
 * issue #30), now available in the required v0.8.0 tag. The method shapes match the contract
 * exactly, so swapping `implements PluginDataStoreInterface` for the contracts one is a one-line
 * change — nothing about {@see PluginDataStore} itself needs to change; kept app-local here as a
 * separate migration, out of scope for the namespace bump (issue #305).
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
     * Replaces this plugin's stored payload for the given anime with the given data and persists
     * it. An override, not a merge (contracts v0.8.0): keys already stored from a previous
     * write() that are absent from $data are removed.
     *
     * @param array<string, mixed> $data
     */
    public function write(AnimeId $anime, array $data): void;
}
