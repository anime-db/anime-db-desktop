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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Resolves {@see FillerRegistry::fillerAvailability()} to a trans-key "kind" and a concrete URL
 * (issue #833): shared by the "search in plugins" screen's own no-filler explanation
 * (`AnimeSearchPluginsController::index()`) and the empty-catalog "Search in plugins" card
 * (`HomeController::index()`), so the market/settings-plugins/settings-plugin-page routing
 * decision is made in exactly one place instead of once per caller.
 *
 * Not `final`: callers that only care about the empty-catalog card or the search-plugins
 * screen's own no-filler explanation (HomeControllerTest, AnimeSearchPluginsControllerTest) mock
 * this class directly rather than wiring a real FillerRegistry/InstalledPluginsRegistry/
 * SettingsPageRegistry trio just to get a fixed two-value result — PHPUnit cannot double a
 * `final` class, same reasoning as {@see SettingsPageRegistry}.
 */
class FillerAvailabilityPresenter
{
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly SettingsPageRegistry $settingsPages,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function hasActiveFiller(): bool
    {
        return $this->fillerRegistry->findAllActive() !== [];
    }

    /**
     * Only meaningful when {@see self::hasActiveFiller()} is false.
     *
     * @return array{kind: string, url: string} kind is one of 'not_installed'/'disabled', for the
     *                                          caller's template to pick the matching trans key
     */
    public function describeUnavailable(): array
    {
        $availability = $this->fillerRegistry->fillerAvailability($this->installedPlugins, $this->settingsPages);

        return match ($availability->state) {
            FillerAvailabilityState::NotInstalled => [
                'kind' => 'not_installed',
                'url' => $this->urlGenerator->generate('settings_market_index'),
            ],
            FillerAvailabilityState::DisabledNoSettingsPage => [
                'kind' => 'disabled',
                'url' => $this->urlGenerator->generate('settings_plugins_index'),
            ],
            FillerAvailabilityState::DisabledWithSettingsPage => [
                'kind' => 'disabled',
                'url' => $this->urlGenerator->generate('settings_plugin_page', ['pluginId' => (string) $availability->pluginId]),
            ],
        };
    }
}
