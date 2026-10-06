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

namespace App\Tests\Unit\Service\Sync;

use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalPlan;
use App\Service\Sync\SourceRemovalPlanner;
use PHPUnit\Framework\TestCase;

final class SourceRemovalPlannerTest extends TestCase
{
    /**
     * @param array<string, SyncInterface> $syncs         plugin id => plugin
     * @param list<string>                 $activeSyncIds ids with sync switched on
     */
    private function newSyncRegistryOf(array $syncs, array $activeSyncIds): SyncRegistry
    {
        $settings = [];
        foreach ($activeSyncIds as $id) {
            $settings[$id] = ['features' => ['sync' => true]];
        }

        $path = sys_get_temp_dir().'/source-removal-planner-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($settings));

        return new SyncRegistry($syncs, new PluginsConfigStore($path));
    }

    /** @param array<string, string> $externalIds */
    private function anime(array $externalIds): TvAnime
    {
        $anime = new TvAnime();
        foreach ($externalIds as $pluginId => $externalId) {
            $anime->rememberExternalId(new PluginId($pluginId), $externalId);
        }

        return $anime;
    }

    public function testSplitsSourcesIntoTargetsAndKeptWithTheReason(): void
    {
        $registry = $this->newSyncRegistryOf([
            'acme-removable' => $this->createStub(SyncRemovalInterface::class),
            'acme-plain' => $this->createStub(SyncInterface::class),
            'acme-off' => $this->createStub(SyncRemovalInterface::class),
        ], ['acme-removable', 'acme-plain']);

        $plan = (new SourceRemovalPlanner($registry))->plan($this->anime(['acme-removable' => '11', 'acme-plain' => '22', 'acme-off' => '33']));

        $this->assertTrue($plan->hasTargets());
        $this->assertSame(['acme-removable' => '11'], $plan->targets);
        $this->assertSame(['acme-removable'], $plan->targetNames);
        $this->assertSame([
            ['name' => 'acme-plain', 'reason' => SourceRemovalPlan::REASON_NO_REMOVAL],
            ['name' => 'acme-off', 'reason' => SourceRemovalPlan::REASON_INACTIVE],
        ], $plan->kept);
    }

    public function testNoActiveSourceWithRemovalMeansNoTargets(): void
    {
        $registry = $this->newSyncRegistryOf(['acme-plain' => $this->createStub(SyncInterface::class)], ['acme-plain']);

        $this->assertFalse((new SourceRemovalPlanner($registry))->plan($this->anime(['acme-plain' => '1']))->hasTargets());
        $this->assertFalse((new SourceRemovalPlanner($registry))->plan($this->anime([]))->hasTargets());
    }

    public function testASourceWithoutACachedExternalIdIsNotATarget(): void
    {
        $registry = $this->newSyncRegistryOf(['acme-removable' => $this->createStub(SyncRemovalInterface::class)], ['acme-removable']);

        $this->assertSame([], (new SourceRemovalPlanner($registry))->plan($this->anime([]))->targets);
    }

    public function testAnExcludedSourceIsNeitherATargetNorListedAsStaying(): void
    {
        $registry = $this->newSyncRegistryOf([
            'acme-gone' => $this->createStub(SyncRemovalInterface::class),
            'acme-other' => $this->createStub(SyncRemovalInterface::class),
        ], ['acme-gone', 'acme-other']);

        $plan = (new SourceRemovalPlanner($registry))->plan($this->anime(['acme-gone' => '1', 'acme-other' => '2']), ['acme-gone']);

        $this->assertSame(['acme-other' => '2'], $plan->targets);
        $this->assertSame([], $plan->kept);
    }

    public function testAFillerOnlyPluginIsNotListedAsStaying(): void
    {
        $registry = $this->newSyncRegistryOf(['acme-removable' => $this->createStub(SyncRemovalInterface::class)], ['acme-removable']);

        $plan = (new SourceRemovalPlanner($registry))->plan($this->anime(['acme-removable' => '1', 'acme-filler' => '9']));

        $this->assertSame([], $plan->kept);
    }
}
