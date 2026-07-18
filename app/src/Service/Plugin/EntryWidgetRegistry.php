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

use AnimeDb\PluginContracts\EntryWidgetInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see EntryWidgetInterface} instance a single `/plugin/{pluginId}/widget/{widgetName}`
 * request (issue #212) is for, and lists the active ones so the anime detail page can render one
 * HTMX placeholder per widget.
 *
 * A plugin may declare several entry widgets (one class per widget, see the interface's PHPDoc),
 * unlike {@see FillerInterface} where the registry only ever needs one instance per plugin. The
 * service id therefore doubles as a compound "{pluginId}:{widgetName}" key rather than plain
 * PluginId, and — same reasoning as FillerRegistry — is expected to be assigned by the future
 * plugin manager (issues #218/#220-224, not implemented yet). Until then this iterable is simply
 * empty in production.
 *
 * Each widget is toggled independently: `isActive()` reads `features.{$widgetName}` from
 * plugins.json, not a single shared "widget" flag, so disabling one widget of a plugin never
 * touches its other widgets or its Filler/Sync features.
 */
final class EntryWidgetRegistry
{
    use WidgetActiveTrait;

    /** @param iterable<string, EntryWidgetInterface> $widgets keyed by "{pluginId}:{widgetName}" */
    public function __construct(
        #[AutowireIterator('app.entry_widget', indexAttribute: 'id')]
        private readonly iterable $widgets,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    public function find(PluginId $pluginId, string $widgetName): ?EntryWidgetInterface
    {
        $widget = $this->all()[self::key($pluginId, $widgetName)] ?? null;

        return $widget !== null && $this->isActive($pluginId, $widgetName) ? $widget : null;
    }

    /**
     * @return list<array{pluginId: string, widgetName: string}> active widget identifiers, for
     *                                                           the anime detail page to render one HTMX placeholder per widget
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

    /** @return array<string, EntryWidgetInterface> */
    private function all(): array
    {
        return iterator_to_array($this->widgets);
    }

    private static function key(PluginId $pluginId, string $widgetName): string
    {
        return $pluginId.':'.$widgetName;
    }
}
