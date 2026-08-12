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

namespace App\Service\Plugin\Exception;

/**
 * Thrown by {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass}
 * (issue #364) when a widget's `metadata()->name` collides with a reserved key of the `features`
 * map in plugins.json. `filler` and `sync` already gate the plugin's Filler/Sync capabilities
 * there ({@see \App\Service\Plugin\FillerActiveTrait}, {@see \App\Service\Plugin\SyncRegistry}),
 * and per-widget toggles ({@see \App\Service\Plugin\WidgetActiveTrait}) live in that very same
 * map — a widget named e.g. "filler" would silently flip the plugin's filler feature instead of
 * (or as well as) its own visibility.
 */
final class ReservedWidgetNameException extends \LogicException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $serviceId,
        public readonly string $widgetName,
    ) {
        parent::__construct(\sprintf(
            'Widget service "%s" of plugin "%s" has metadata() name "%s", which collides with a'
            .' reserved features key (filler/sync): choose a different widget name.',
            $serviceId,
            $pluginId,
            $widgetName,
        ));
    }
}
