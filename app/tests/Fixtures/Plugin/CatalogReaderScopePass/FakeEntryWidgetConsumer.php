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

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;

/**
 * Fixture used by CatalogReaderScopePassTest to stand in for a real entry widget that consumes
 * {@see CatalogReaderInterface} — the same shape as Shikimori's RelatedWidget/SimilarWidget
 * (issue #577). `EntryWidgetInterface` extends `ExternalIdResolutionInterface`, so this class is
 * itself technically eligible as an external-id resolver; the pass under test must never pick it
 * as its own plugin's resolver, since that would make the CatalogReader instance injected into it
 * depend on this very service.
 */
final class FakeEntryWidgetConsumer implements EntryWidgetInterface
{
    public function __construct(
        public readonly CatalogReaderInterface $catalogReader,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata('fake-widget', 'widget.title', 'widget.description');
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
