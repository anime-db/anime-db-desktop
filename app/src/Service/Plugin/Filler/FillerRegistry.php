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

namespace App\Service\Plugin\Filler;

use AnimeDb\PluginContracts\FillerInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up an installed {@see FillerInterface} plugin by its {@see PluginId} for the bulk and
 * point fill-in scenarios (issue #227). Unlike {@see \App\Service\Storage\Search\SearchByPluginInterface},
 * FillerInterface is contract-owned (anime-db/plugin-contracts), not app-owned, so it cannot
 * carry an app-defined `#[AutoconfigureTag]` attribute directly; concrete plugin services are
 * tagged from services.yaml's `_instanceof:` section instead, and must additionally declare a
 * `plugin_id` tag attribute — PluginInterface itself never reports its own id (see PluginId's
 * docblock: identity is host-side infrastructure's responsibility, not the plugin's).
 *
 * No concrete FillerInterface plugin ships in this repository yet (same status as
 * SearchByPluginChain's search plugins): get() simply returns null for every id until a real
 * plugin is installed and tagged, so every caller must already treat a missing filler as a
 * normal, expected case rather than an error.
 */
final class FillerRegistry
{
    /** @param iterable<string, FillerInterface> $fillers keyed by the `plugin_id` tag attribute */
    public function __construct(
        #[AutowireIterator('app.filler', indexAttribute: 'plugin_id')]
        private readonly iterable $fillers,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    /**
     * Returns null both when no plugin is registered for $pluginId and when one is registered
     * but has been switched off (metadata['active'] === false in plugins.json) — callers do not
     * need to distinguish the two, they just fall back to not filling in anything.
     */
    public function get(PluginId $pluginId): ?FillerInterface
    {
        foreach ($this->fillers as $id => $filler) {
            if ($id === (string) $pluginId) {
                return $this->isActive($pluginId) ? $filler : null;
            }
        }

        return null;
    }

    private function isActive(PluginId $pluginId): bool
    {
        return (bool) ($this->pluginsConfigStore->getPluginSettings($pluginId)['active'] ?? true);
    }
}
