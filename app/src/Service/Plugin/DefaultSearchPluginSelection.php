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
 * Backs the "default search plugin" select on the plugins settings page (issue #1019): which
 * search plugins can be chosen, which one is chosen now, and storing a new choice through
 * {@see DefaultSearchPluginRegistry::setDefault()}.
 *
 * The current choice is read via {@see AppSettingsProvider::getDefaultSearchPluginId()}, never
 * {@see DefaultSearchPluginRegistry::getDefault()}: that one persists a fallback pick, which
 * would make "nothing chosen" indistinguishable from a real choice. A stored id that is not an
 * available plugin any more is shown as "not chosen" and left untouched in the settings.
 */
final class DefaultSearchPluginSelection
{
    use FillerActiveTrait;

    /** @param iterable<string, SearchByPluginInterface> $plugins keyed by plugin id */
    public function __construct(
        #[AutowireIterator('app.search_by_plugin', indexAttribute: 'id')]
        private readonly iterable $plugins,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly AppSettingsProvider $appSettings,
        private readonly DefaultSearchPluginRegistry $registry,
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

    /**
     * @return bool false (nothing stored) when $pluginId is not an available search plugin;
     *              null clears the choice
     */
    public function select(?PluginId $pluginId): bool
    {
        if ($pluginId !== null && !\in_array((string) $pluginId, array_map('strval', $this->available()), true)) {
            return false;
        }

        $this->registry->setDefault($pluginId);

        return true;
    }
}
