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

namespace App\Tests\Fixtures\Plugin\Widget;

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;

/**
 * Minimal EntryWidgetInterface double for registry/controller tests that need a real class (issue
 * #364: `metadata()` is static, so a PHPUnit stub can't provide it — see
 * {@see \App\Tests\Unit\Service\Plugin\EntryWidgetRegistryTest}). `widgetName` in test assertions
 * comes from the compound array key the tests key their widgets under, not from
 * {@see self::metadata()}, so a single shared fixture class covers every widget in a test.
 */
final class FakeEntryWidget implements EntryWidgetInterface
{
    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata('fake-entry-widget', 'widget.fake_entry_widget.title', 'widget.fake_entry_widget.description');
    }

    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    public function render(AnimeId $anime): string
    {
        return '';
    }
}
