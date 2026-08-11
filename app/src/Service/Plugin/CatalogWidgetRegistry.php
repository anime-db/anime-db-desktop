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

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see CatalogWidgetInterface} instance a single `/plugin/{pluginId}/widget/{widgetName}`
 * request (issue #212) is for, and lists the widgets (active or not) for the settings UI (issue
 * #213). Same compound "{pluginId}:{widgetName}" service-id convention as {@see EntryWidgetRegistry}
 * — see that class for why. Empty in production until the plugin manager (issues #218/#220-224)
 * exists.
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
        $widget = $this->all()[self::key($pluginId, $widgetName)] ?? null;

        return $widget !== null && $this->isActive($pluginId, $widgetName) ? $widget : null;
    }

    /**
     * @return list<array{pluginId: string, widgetName: string}> active widget identifiers
     */
    public function findAllActive(): array
    {
        $result = [];
        foreach (array_keys($this->all()) as $key) {
            [$pluginId, $widgetName] = explode(':', $key, 2);
            if ($this->isActive(new PluginId($pluginId), $widgetName)) {
                $result[] = ['pluginId' => $pluginId, 'widgetName' => $widgetName];
            }
        }

        return $result;
    }

    /**
     * Title/description come from the widget's own `metadata()` (issue #364), read fresh on
     * every call rather than cached: the widget instances themselves are already resolved once
     * at container build time, so this is not a repeated I/O cost, just a couple of property
     * reads on an already-live object.
     *
     * @return list<array{pluginId: string, widgetName: string, active: bool, title: string, description: string}> every
     *                                                                                                             registered widget, active or not, for the settings UI (issue #213/#364)
     */
    public function listAll(): array
    {
        $result = [];
        foreach ($this->all() as $key => $widget) {
            [$pluginId, $widgetName] = explode(':', $key, 2);
            $metadata = $widget::metadata();
            $result[] = [
                'pluginId' => $pluginId,
                'widgetName' => $widgetName,
                'active' => $this->isActive(new PluginId($pluginId), $widgetName),
                'title' => $metadata->title,
                'description' => $metadata->description,
            ];
        }

        return $result;
    }

    /**
     * Enables or disables a single catalog widget, enforcing the placement's hard limit of
     * simultaneously active widgets (issue #213).
     *
     * @throws Exception\WidgetHardLimitExceededException
     * @throws Exception\PluginsConfigStoreLockedException
     */
    public function setActive(PluginId $pluginId, string $widgetName, bool $active): void
    {
        $this->changeActive($pluginId, $widgetName, $active, \count($this->findAllActive()));
    }

    /** @return array<string, CatalogWidgetInterface> */
    private function all(): array
    {
        return iterator_to_array($this->widgets);
    }

    private static function key(PluginId $pluginId, string $widgetName): string
    {
        return $pluginId.':'.$widgetName;
    }
}
