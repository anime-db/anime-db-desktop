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

use AnimeDb\PluginContracts\CatalogWidgetInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see CatalogWidgetInterface} instance a single `/plugin/{pluginId}/widget/{widgetName}`
 * request (issue #212) is for. Same compound "{pluginId}:{widgetName}" service-id convention as
 * {@see EntryWidgetRegistry} — see that class for why. Empty in production until the plugin
 * manager (issues #218/#220-224) exists.
 */
final class CatalogWidgetRegistry
{
    use WidgetActiveTrait;

    /** @param iterable<string, CatalogWidgetInterface> $widgets keyed by "{pluginId}:{widgetName}" */
    public function __construct(
        #[AutowireIterator('app.catalog_widget', indexAttribute: 'id')]
        private readonly iterable $widgets,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    public function find(PluginId $pluginId, string $widgetName): ?CatalogWidgetInterface
    {
        $key = $pluginId.':'.$widgetName;
        $widget = iterator_to_array($this->widgets)[$key] ?? null;

        return $widget !== null && $this->isActive($pluginId, $widgetName) ? $widget : null;
    }
}
