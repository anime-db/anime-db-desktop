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

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginsConfigStoreLockedException;
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;

/**
 * Shared by {@see EntryWidgetRegistry} and {@see CatalogWidgetRegistry}: each widget is toggled
 * independently via `features.{$widgetName}` in plugins.json, not a single shared "widget" flag,
 * so disabling one widget of a plugin never touches its other widgets or its Filler/Sync features.
 *
 * Issue #213: a placement (anime detail page vs. catalog — each registry covers exactly one)
 * has a hard cap of simultaneously active widgets and a soft {@see self::RECOMMENDED_LIMIT}
 * shown to the user as a performance/clutter hint that never blocks enabling a widget.
 *
 * The hard cap itself is *not* declared here (issue #728): each using class declares its own
 * `HARD_LIMIT` constant instead — {@see EntryWidgetRegistry::HARD_LIMIT} and
 * {@see CatalogWidgetRegistry::HARD_LIMIT} differ, and a trait cannot itself declare a constant
 * that a using class then redeclares with a different value (PHP treats that as an incompatible
 * redeclaration, not an override). {@see self::changeActive()} reads it through `static::`, late
 * static binding, which resolves to whichever class the trait is actually composed into.
 */
trait WidgetActiveTrait
{
    public const int RECOMMENDED_LIMIT = 2;

    private readonly PluginsConfigStore $pluginsConfigStore;

    /**
     * A widget without a recorded settings entry yet is treated as inactive: the user must opt
     * in explicitly. This keeps `HARD_LIMIT` meaningful for placements with more than
     * `HARD_LIMIT` installed widgets — a default-on widget would bypass the cap simply by never
     * being toggled.
     */
    private function isActive(PluginId $pluginId, string $widgetName): bool
    {
        $settings = $this->pluginsConfigStore->getPluginSettings($pluginId);
        $features = $settings['features'] ?? [];

        return (bool) ($features[$widgetName] ?? false);
    }

    /**
     * Persists `features.{$widgetName}` for a single widget. Turning a widget on is rejected
     * once $activeCount already reached `HARD_LIMIT` for this placement — turning one off, or
     * toggling an already-active widget back on, is always allowed.
     *
     * @throws WidgetHardLimitExceededException
     * @throws PluginsConfigStoreLockedException
     */
    private function changeActive(PluginId $pluginId, string $widgetName, bool $active, int $activeCount): void
    {
        if ($active && !$this->isActive($pluginId, $widgetName) && $activeCount >= static::HARD_LIMIT) {
            throw new WidgetHardLimitExceededException($pluginId, $widgetName, static::HARD_LIMIT);
        }

        $this->pluginsConfigStore->updatePluginSettings($pluginId, static function (array $settings) use ($widgetName, $active): array {
            $settings['features'][$widgetName] = $active;

            return $settings;
        });
    }
}
