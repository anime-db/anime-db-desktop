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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\CatalogWidgetInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class CatalogWidgetRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-widgets-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testFindReturnsTheMatchingWidgetForACompoundPluginAndWidgetNameKey(): void
    {
        $newReleases = $this->createStub(CatalogWidgetInterface::class);

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $newReleases],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($newReleases, $registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindReturnsNullWhenNoWidgetIsRegisteredUnderThatKey(): void
    {
        $registry = new CatalogWidgetRegistry([], new PluginsConfigStore($this->path));

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindReturnsNullWhenTheWidgetIsDisabled(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class)],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindAllActiveListsOnlyEnabledWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
        ]));

        $registry = new CatalogWidgetRegistry(
            [
                'animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class),
                'animedb-anilist:trending' => $this->createStub(CatalogWidgetInterface::class),
            ],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(
            [['pluginId' => 'animedb-anilist', 'widgetName' => 'trending']],
            $registry->findAllActive(),
        );
    }

    public function testListAllIncludesBothActiveAndInactiveWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class)],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(
            [['pluginId' => 'animedb-shikimori', 'widgetName' => 'new_releases', 'active' => false]],
            $registry->listAll(),
        );
    }

    public function testSetActiveTurnsAWidgetOnAndOff(): void
    {
        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class)],
            new PluginsConfigStore($this->path),
        );

        $registry->setActive(new PluginId('animedb-shikimori'), 'new_releases', false);
        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'new_releases', true);
        $this->assertNotNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testSetActiveThrowsWhenEnablingAWidgetWouldExceedTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w6' => false]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5', 'w6'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = $this->createStub(CatalogWidgetInterface::class);
        }

        $registry = new CatalogWidgetRegistry($widgets, new PluginsConfigStore($this->path));

        $this->expectException(WidgetHardLimitExceededException::class);
        $registry->setActive(new PluginId('animedb-shikimori'), 'w6', true);
    }
}
