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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The app's default search plugin: which installed {@see SearchByPluginInterface} plugins can be
 * chosen, which one is chosen now, and storing a new choice (issue #1019).
 *
 * The choice is explicit only: nothing is ever picked implicitly and nothing is written to the
 * settings by a read. {@see self::selected()} returns the stored choice while that plugin is
 * available, otherwise null ("not chosen"). A stored id that is not available any more (plugin
 * uninstalled, disabled, or its filler feature turned off) stays untouched in the settings and is
 * exposed through {@see self::unavailableSelected()}, so a settings page can show it instead of
 * pretending nothing is chosen; it takes effect again as soon as the plugin is available.
 *
 * "Available" means an active search capability — the same active-gating as
 * {@see \App\Service\Storage\Search\SearchByPluginChain}: a plugin whose filler feature is off
 * ({@see FillerActiveTrait}) is unavailable here too, for the same reason that chain skips it.
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

    /** @return list<PluginId> plugins with an active search capability, in registration order */
    public function available(): array
    {
        $ids = [];
        foreach ($this->plugins as $id => $plugin) {
            $pluginId = new PluginId((string) $id);
            if ($this->isFillerActive($pluginId)) {
                $ids[] = $pluginId;
            }
        }

        return $ids;
    }

    /** The stored choice while that plugin is available, null otherwise. Never writes. */
    public function selected(): ?PluginId
    {
        $configured = $this->appSettings->getDefaultSearchPluginId();
        if ($configured === null) {
            return null;
        }

        foreach ($this->available() as $pluginId) {
            if ((string) $pluginId === (string) $configured) {
                return $configured;
            }
        }

        return null;
    }

    /** The stored choice when it is not an available search plugin right now, null otherwise. */
    public function unavailableSelected(): ?PluginId
    {
        $configured = $this->appSettings->getDefaultSearchPluginId();
        if ($configured === null || $this->selected() !== null) {
            return null;
        }

        return $configured;
    }

    /**
     * Only an available plugin can be chosen. Re-submitting the stored choice that is unavailable
     * right now is a no-op, not an error. Null clears the choice.
     *
     * @return bool false (nothing stored) when $pluginId is not an available search plugin
     */
    public function select(?PluginId $pluginId): bool
    {
        if ($pluginId !== null && (string) $pluginId === (string) $this->unavailableSelected()) {
            return true;
        }

        if ($pluginId !== null && !\in_array((string) $pluginId, array_map('strval', $this->available()), true)) {
            return false;
        }

        $this->appSettings->setDefaultSearchPluginId($pluginId);

        return true;
    }
}
