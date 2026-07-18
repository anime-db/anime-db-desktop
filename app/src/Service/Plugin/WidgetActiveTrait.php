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
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;

/**
 * Shared by {@see EntryWidgetRegistry} and {@see CatalogWidgetRegistry}: each widget is toggled
 * independently via `features.{$widgetName}` in plugins.json, not a single shared "widget" flag,
 * so disabling one widget of a plugin never touches its other widgets or its Filler/Sync features.
 *
 * Issue #213: a placement (anime detail page vs. catalog — each registry covers exactly one)
 * has a hard cap of {@see self::HARD_LIMIT} simultaneously active widgets and a soft
 * {@see self::RECOMMENDED_LIMIT} shown to the user as a performance/clutter hint that never
 * blocks enabling a widget.
 */
trait WidgetActiveTrait
{
    public const int HARD_LIMIT = 5;
    public const int RECOMMENDED_LIMIT = 2;

    private readonly PluginsConfigStore $pluginsConfigStore;

    /**
     * A widget without a recorded settings entry yet is treated as inactive: the user must opt
     * in explicitly. This keeps {@see self::HARD_LIMIT} meaningful for placements with more than
     * {@see self::HARD_LIMIT} installed widgets — a default-on widget would bypass the cap simply
     * by never being toggled.
     */
    private function isActive(PluginId $pluginId, string $widgetName): bool
    {
        $settings = $this->pluginsConfigStore->getPluginSettings($pluginId);
        $features = $settings['features'] ?? [];

        return (bool) ($features[$widgetName] ?? false);
    }

    /**
     * Persists `features.{$widgetName}` for a single widget. Turning a widget on is rejected
     * once $activeCount already reached {@see self::HARD_LIMIT} for this placement — turning one
     * off, or toggling an already-active widget back on, is always allowed.
     *
     * @throws WidgetHardLimitExceededException
     */
    private function changeActive(PluginId $pluginId, string $widgetName, bool $active, int $activeCount): void
    {
        if ($active && !$this->isActive($pluginId, $widgetName) && $activeCount >= self::HARD_LIMIT) {
            throw new WidgetHardLimitExceededException($pluginId, $widgetName, self::HARD_LIMIT);
        }

        $this->pluginsConfigStore->updatePluginSettings($pluginId, static function (array $settings) use ($widgetName, $active): array {
            $settings['features'][$widgetName] = $active;

            return $settings;
        });
    }
}
