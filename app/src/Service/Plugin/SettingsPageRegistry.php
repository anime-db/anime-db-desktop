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

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the settings page behind `GET /settings/plugins/{pluginId}` (issue #317, the Chrome
 * `options_ui` model), gated purely on whether the *whole* plugin is enabled
 * ({@see InstalledPluginsRegistry}), never on a `features.*` flag: this is the page where the
 * user flips `enabled` itself and completes OAuth, so gating access on a feature flag would be a
 * deadlock the user could never get out of through the UI.
 *
 * `SettingsPageInterface` lives in the read-only `anime-db/plugin-contracts` package and cannot
 * carry `#[AutoconfigureTag]` itself, so it is tagged 'app.settings_page' at compile time by
 * {@see DependencyInjection\Compiler\TagPluginServicesPass} — same mechanism as
 * `app.filler`/`app.sync`/the widget tags. Unlike the widget registries, a plugin has at most one
 * settings page (the compiler pass rejects a second one under the same plugin id outright), so
 * the injected iterable is keyed by plain {@see PluginId}, the same shape as {@see FillerRegistry}.
 */
final class SettingsPageRegistry
{
    /** @param iterable<string, SettingsPageInterface> $pages keyed by plugin id */
    public function __construct(
        #[AutowireIterator('app.settings_page', indexAttribute: 'id')]
        private readonly iterable $pages,
        private readonly InstalledPluginsRegistry $installedPlugins,
    ) {
    }

    public function find(PluginId $pluginId): ?SettingsPageInterface
    {
        $plugin = $this->installedPlugins->get($pluginId);
        if ($plugin === null || !$plugin->enabled) {
            return null;
        }

        return iterator_to_array($this->pages)[(string) $pluginId] ?? null;
    }
}
