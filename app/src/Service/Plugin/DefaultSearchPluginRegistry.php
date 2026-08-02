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

use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tracks which installed {@see SearchByPluginInterface} plugin is the app's default search
 * plugin, cascading automatically once the configured one is no longer available: uninstalled,
 * disabled, or its filler feature turned off (issue #293 item 5, carried over from v1's
 * install-wizard "default search plugin" setting, `context/todo.md` in the workspace).
 *
 * There is no explicit "plugin removed" event to hook in this app yet (plugin removal itself is
 * a separate, not-yet-built feature, issue #225) — the cascade instead runs lazily on every
 * {@see self::getDefault()} call, comparing the persisted id ({@see AppSettingsProvider}) against
 * whichever search plugins are actually available *now*. Because the DI container is fully
 * rebuilt after any plugin mutation ("Atomic Cache Swap", see architecture docs), the very next
 * read after an install/remove/enable/disable already sees the post-mutation set of plugins, so
 * no separate hook is needed: a stale configured id is corrected (and persisted) the first time
 * it is asked for, and if no search plugin is left at all, the setting is cleared and search is
 * effectively disabled.
 *
 * Same active-gating as {@see \App\Service\Storage\Search\SearchByPluginChain}: a plugin whose
 * filler feature is off ({@see FillerActiveTrait}) is treated as unavailable here too, for the
 * same reason that chain skips it — see that class for the "pure search plugin" exception.
 */
final class DefaultSearchPluginRegistry
{
    use FillerActiveTrait;

    /** @param iterable<string, SearchByPluginInterface> $plugins keyed by plugin id */
    public function __construct(
        #[AutowireIterator('app.search_by_plugin', indexAttribute: 'id')]
        private readonly iterable $plugins,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly AppSettingsProvider $appSettings,
    ) {
    }

    /**
     * Returns the current default search plugin id, cascading to the next available one (or to
     * null, if none is left) when the previously configured plugin is no longer available.
     */
    public function getDefault(): ?PluginId
    {
        $available = $this->availablePluginIds();

        $configured = $this->appSettings->getDefaultSearchPluginId();
        if ($configured !== null && \in_array((string) $configured, $available, true)) {
            return $configured;
        }

        $fallbackId = $available !== [] ? new PluginId($available[0]) : null;
        $this->appSettings->setDefaultSearchPluginId($fallbackId);

        return $fallbackId;
    }

    /**
     * Explicitly picks the default search plugin, e.g. from a future settings page. Not
     * restricted to $this->plugins on purpose: {@see self::getDefault()} already re-validates
     * availability on every read, so a temporarily-disabled plugin can still be set as the
     * intended default ahead of being re-enabled.
     */
    public function setDefault(?PluginId $pluginId): void
    {
        $this->appSettings->setDefaultSearchPluginId($pluginId);
    }

    /** @return list<string> plugin ids with an active search capability, in registration order */
    private function availablePluginIds(): array
    {
        $ids = [];
        foreach ($this->plugins as $id => $plugin) {
            if ($this->isFillerActive(new PluginId((string) $id))) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
