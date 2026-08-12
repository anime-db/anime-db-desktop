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
 * (issue #364) when two of a plugin's widget services share the same `metadata()->name` — checked
 * across both {@see \AnimeDb\PluginContracts\Widget\EntryWidgetInterface} and
 * {@see \AnimeDb\PluginContracts\Widget\CatalogWidgetInterface}, since the widget registries key
 * on the compound "{pluginId}:{name}" id regardless of placement. Left unchecked,
 * `#[AutowireIterator(indexAttribute: 'id')]` would silently keep only whichever service compiles
 * last, the same failure mode {@see MultipleSettingsPagesException} guards against for settings
 * pages.
 */
final class DuplicateWidgetNameException extends \LogicException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $widgetName,
        public readonly string $firstServiceId,
        public readonly string $secondServiceId,
    ) {
        parent::__construct(\sprintf(
            'Plugin "%s" registers more than one widget service named "%s" ("%s" and "%s"): widget'
            .' names must be unique within a plugin, across both entry and catalog widgets.',
            $pluginId,
            $widgetName,
            $firstServiceId,
            $secondServiceId,
        ));
    }
}
