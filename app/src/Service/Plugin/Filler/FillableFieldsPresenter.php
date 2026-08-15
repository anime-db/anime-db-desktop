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

namespace App\Service\Plugin\Filler;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;

/**
 * Builds the view data anime/_fill_fields.html.twig needs to decide, per field, whether to show
 * a "fill from source" button at all and, when several active fillers support the same field,
 * which plugins to list in the source dropdown (issue #234).
 *
 * Only the card fields that already have a display slot in anime/_fill_fields.html.twig are
 * covered here - title, descriptions, cover and images live in other sections of the card and
 * are left for a follow-up, and datePremiere/dateEnd are not shown anywhere on the card yet.
 */
final class FillableFieldsPresenter
{
    /** @var list<string> */
    private const FIELDS = [
        'alternativeNames',
        'genres',
        'themes',
        'demographic',
        'studios',
        'durationMinutes',
        'episodesCount',
        'countries',
    ];

    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly InstalledPluginsRegistry $installedPlugins,
    ) {
    }

    /** @return array<string, list<array{id: string, name: string}>> field => active plugins supporting it */
    public function build(): array
    {
        $result = [];
        foreach (self::FIELDS as $field) {
            $result[$field] = array_map(
                fn (string $pluginId): array => ['id' => $pluginId, 'name' => $this->pluginName($pluginId)],
                array_keys($this->fillerRegistry->findWithIdByField($field)),
            );
        }

        return $result;
    }

    private function pluginName(string $pluginId): string
    {
        return $this->installedPlugins->get(new PluginId($pluginId))?->manifest->name ?? $pluginId;
    }
}
