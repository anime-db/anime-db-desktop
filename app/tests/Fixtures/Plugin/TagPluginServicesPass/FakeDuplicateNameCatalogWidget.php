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

namespace AnimeDb\Plugins\FakeVendor;

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;

/**
 * Fixture used by TagPluginServicesPassTest: a catalog widget whose `metadata()->name` collides
 * with {@see FakeEntryWidget}'s, to prove the pass under test dedups widget names per plugin
 * across both entry and catalog placements, not just within one (issue #364).
 */
final class FakeDuplicateNameCatalogWidget implements CatalogWidgetInterface
{
    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata('related', 'Related titles (catalog)', 'Duplicate name fixture.');
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
