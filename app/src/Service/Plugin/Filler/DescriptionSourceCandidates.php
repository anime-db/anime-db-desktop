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

use App\Entity\AnimeDescription;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use Doctrine\DBAL\Connection;

/**
 * The plugins the "description sources" settings screen lets the user rank (issue #1069): active
 * fillers that can fill 'descriptions', plus installed plugins whose id already stands in
 * anime_description.source. The second group matters for a sync plugin with features.filler
 * switched off: it writes descriptions around the FillerRegistry, yet its rows take part in the
 * priority comparison, so it has to be rankable.
 *
 * The order is the saved priority first (ids that are no longer candidates are dropped), then the
 * rest by name.
 */
class DescriptionSourceCandidates
{
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly AppSettingsProvider $settings,
        private readonly Connection $connection,
    ) {
    }

    /** @return list<array{id: string, name: string}> */
    public function list(): array
    {
        $names = [];
        foreach (array_keys($this->fillerRegistry->findWithIdByField('descriptions')) as $id) {
            $names[$id] = $this->pluginName($id);
        }

        /** @var list<string> $stored */
        $stored = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT source FROM anime_description WHERE source IS NOT NULL AND source != ?',
            [AnimeDescription::SOURCE_MANUAL],
        );
        foreach ($stored as $id) {
            if (!isset($names[$id]) && $this->isInstalled($id)) {
                $names[$id] = $this->pluginName($id);
            }
        }

        $result = [];
        foreach ($this->settings->getDescriptionSourcePriority() as $id) {
            if (isset($names[$id])) {
                $result[] = ['id' => $id, 'name' => $names[$id]];
                unset($names[$id]);
            }
        }

        asort($names);
        foreach ($names as $id => $name) {
            $result[] = ['id' => (string) $id, 'name' => $name];
        }

        return $result;
    }

    private function isInstalled(string $id): bool
    {
        try {
            return $this->installedPlugins->has(new PluginId($id));
        } catch (InvalidPluginIdException) {
            return false;
        }
    }

    private function pluginName(string $id): string
    {
        return $this->installedPlugins->get(new PluginId($id))?->manifest->name ?? $id;
    }
}
