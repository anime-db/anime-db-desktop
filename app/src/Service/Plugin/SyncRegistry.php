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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Lists installed, active {@see SyncInterface} plugins for the background push/pull flows
 * (issues #214/#215), which need either one already-known plugin or every active one to run
 * the periodic sync over. Same compiler-pass tagging and `indexAttribute: 'id'` convention as
 * {@see FillerRegistry} — see that class for why `SyncInterface` cannot carry
 * `#[AutoconfigureTag]` itself and why the iterable is keyed by each plugin's own
 * {@see PluginId}.
 *
 * Unlike filler/widgets, sync defaults to *off*: it pushes/pulls the user's list to an external
 * service, which touches privacy and requires credentials, so it only runs once the user opts
 * in via `features.sync` — consistent with the widget default (issue #213).
 */
final class SyncRegistry
{
    /** @param iterable<string, SyncInterface> $syncs */
    public function __construct(
        #[AutowireIterator('app.sync', indexAttribute: 'id')]
        private readonly iterable $syncs,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    /**
     * Resolves a single, already-known plugin without the caller having to enumerate every
     * registered sync itself. Returns null both when no sync is registered under this id and
     * when the matching plugin is installed but sync is disabled (features.sync false, the
     * default).
     */
    public function findByPluginId(PluginId $pluginId): ?SyncInterface
    {
        $id = (string) $pluginId;
        $sync = $this->all()[$id] ?? null;

        return $sync !== null && $this->isActive($id) ? $sync : null;
    }

    /**
     * @return iterable<string, SyncInterface> active plugins, for the periodic push/pull run
     *                                         over every plugin (issues #214/#215)
     */
    public function allActive(): iterable
    {
        foreach ($this->all() as $id => $sync) {
            if ($this->isActive($id)) {
                yield $id => $sync;
            }
        }
    }

    /** @return array<string, SyncInterface> */
    private function all(): array
    {
        return iterator_to_array($this->syncs);
    }

    /**
     * Unlike {@see FillerRegistry::isActive()}, a plugin without recorded settings yet is
     * treated as *inactive*: sync pushes/pulls the user's list to an external service, so it
     * must be explicitly enabled, never on by default.
     */
    private function isActive(string $id): bool
    {
        $settings = $this->pluginsConfigStore->getPluginSettings(new PluginId($id));
        $features = $settings['features'] ?? [];

        return (bool) ($features['sync'] ?? false);
    }
}
