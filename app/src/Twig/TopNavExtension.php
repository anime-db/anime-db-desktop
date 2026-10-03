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

namespace App\Twig;

use App\Service\Plugin\FillerAvailabilityPresenter;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes {@see FillerAvailabilityPresenter} to base.html.twig's "Add" menu (issue #834): whether
 * "Search in plugins" is reachable at all, and — when it is not — which hint/link to show next to
 * it. Reads `plugins.json` and the installed-plugins directory, both cheap and local, so unlike
 * the menu's "Scan" section (see nav/_add_menu_scan_section.html.twig) this runs synchronously on
 * every page render rather than being loaded lazily over htmx.
 */
final class TopNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly FillerAvailabilityPresenter $fillerAvailability,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('top_nav_search_plugins_state', $this->searchPluginsState(...)),
        ];
    }

    /**
     * Runs on every page via base.html.twig, so a failure here must not take the whole page down
     * with it (issue #834 review) — the underlying lookup already avoids instantiating a broken
     * plugin's settings page (see {@see \App\Service\Plugin\FillerRegistry::fillerAvailability()}),
     * but this still fails open to "inactive, no hint" against anything else that could go wrong
     * resolving plugin state, the same way {@see \App\Service\Settings\SettingsNavigationService}
     * degrades its plugin settings sidebar group instead of breaking every settings page (issue #822).
     *
     * @return array{active: bool, hint: array{kind: string, url: string}|null}
     */
    public function searchPluginsState(): array
    {
        try {
            $active = $this->fillerAvailability->hasActiveFiller();

            return [
                'active' => $active,
                'hint' => $active ? null : $this->fillerAvailability->describeUnavailable(),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to resolve filler plugin availability for the top nav Add menu; showing "Search in plugins" as inactive without a hint.', [
                'exception' => $exception,
            ]);

            return ['active' => false, 'hint' => null];
        }
    }
}
