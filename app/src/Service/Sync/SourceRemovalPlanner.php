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

namespace App\Service\Sync;

use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\SyncRegistry;

/**
 * The one place that decides, at the moment of the action, which sources a local deletion of an
 * entry can also reach (issue #918): the active sync plugins ({@see SyncRegistry::findByPluginId()})
 * that implement {@see SyncRemovalInterface} and have a cached external id of the entry. Both the
 * delete dialog and {@see \App\Service\AnimeDeleteService} use it, so what the dialog promises is
 * what the deletion does.
 */
final class SourceRemovalPlanner
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly ?InstalledPluginsRegistry $installedPlugins = null,
    ) {
    }

    /**
     * @param list<string> $excludePluginIds sources the entry is already gone from, they are neither
     *                                       targets nor listed as staying
     */
    public function plan(Anime $anime, array $excludePluginIds = []): SourceRemovalPlan
    {
        $targets = [];
        $targetNames = [];
        $kept = [];

        foreach ($anime->getExternalIdPluginIds() as $pluginId) {
            $id = (string) $pluginId;
            $externalId = $anime->getCachedExternalId($pluginId);
            if ($externalId === null || \in_array($id, $excludePluginIds, true)) {
                continue;
            }

            $sync = $this->syncRegistry->findByPluginId($pluginId);
            if ($sync === null) {
                // Only a source that could be a sync one is worth naming: a filler-only plugin keeps
                // its external id too, but it has no list the entry stays on.
                if ($this->syncRegistry->supports($pluginId)) {
                    $kept[] = ['name' => $this->name($pluginId), 'reason' => SourceRemovalPlan::REASON_INACTIVE];
                }
            } elseif ($sync instanceof SyncRemovalInterface) {
                $targets[$id] = $externalId;
                $targetNames[] = $this->name($pluginId);
            } else {
                $kept[] = ['name' => $this->name($pluginId), 'reason' => SourceRemovalPlan::REASON_NO_REMOVAL];
            }
        }

        return new SourceRemovalPlan($targets, $targetNames, $kept);
    }

    private function name(PluginId $pluginId): string
    {
        return $this->installedPlugins?->get($pluginId)?->manifest->name ?? (string) $pluginId;
    }
}
