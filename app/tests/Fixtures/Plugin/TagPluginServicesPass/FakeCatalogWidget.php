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

namespace AnimeDb\Plugins\FakeVendor;

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;

/**
 * Fixture used by TagPluginServicesPassTest to stand in for a real plugin's catalog widget
 * service (issue #364), living under a plugin namespace so the pass under test recognizes it as
 * belonging to the "fake-vendor" plugin.
 */
final class FakeCatalogWidget implements CatalogWidgetInterface
{
    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata('new_releases', 'New releases', 'Shows recently released anime.');
    }

    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    public function render(): string
    {
        return '';
    }
}
