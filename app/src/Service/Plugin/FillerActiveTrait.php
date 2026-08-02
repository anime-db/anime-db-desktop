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

use App\Entity\ValueObject\PluginId;

/**
 * Shared by {@see FillerRegistry} and {@see \App\Service\Storage\Search\SearchByPluginChain}
 * (issue #280): both need the exact same "is this plugin's filler feature active" check — the
 * registry to decide which {@see \AnimeDb\PluginContracts\Filler\FillerInterface} instances to hand
 * out, the search chain to skip a plugin's find() entirely once its filler is off, since a
 * storage scan's search only exists to feed the bulk-fill that a disabled filler would then
 * reject anyway.
 *
 * A plugin without a recorded `features.filler` entry is treated as active: plugins.json only
 * ever records an explicit "false" once the user turns the feature off, so the key's absence is
 * not a signal to exclude the plugin. This also covers a "pure" search plugin that implements
 * only {@see \AnimeDb\PluginContracts\Search\SearchByPluginInterface} and therefore never exposes a
 * filler toggle in the first place — by design there is nothing to gate it on, so it stays
 * active.
 */
trait FillerActiveTrait
{
    private readonly PluginsConfigStore $pluginsConfigStore;

    private function isFillerActive(PluginId $pluginId): bool
    {
        $settings = $this->pluginsConfigStore->getPluginSettings($pluginId);
        $features = $settings['features'] ?? [];

        return (bool) ($features['filler'] ?? true);
    }
}
