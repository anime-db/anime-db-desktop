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

use AnimeDb\PluginContracts\EntryWidgetInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class EntryWidgetRegistryTest extends TestCase
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
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => true, 'recommended' => true]],
        ]));

        $related = $this->createStub(EntryWidgetInterface::class);
        $recommended = $this->createStub(EntryWidgetInterface::class);

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $related, 'animedb-shikimori:recommended' => $recommended],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($related, $registry->find(new PluginId('animedb-shikimori'), 'related'));
        $this->assertSame($recommended, $registry->find(new PluginId('animedb-shikimori'), 'recommended'));
    }

    public function testFindReturnsNullWhenNoWidgetIsRegisteredUnderThatKey(): void
    {
        $registry = new EntryWidgetRegistry([], new PluginsConfigStore($this->path));

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));
    }

    public function testFindReturnsNullWhenTheWidgetIsDisabledIndependentlyOfOtherWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => false, 'recommended' => true]],
        ]));

        $related = $this->createStub(EntryWidgetInterface::class);
        $recommended = $this->createStub(EntryWidgetInterface::class);

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $related, 'animedb-shikimori:recommended' => $recommended],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));
        $this->assertSame($recommended, $registry->find(new PluginId('animedb-shikimori'), 'recommended'));
    }

    public function testFindAllActiveListsOnlyEnabledWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => false, 'recommended' => true]],
            'animedb-anilist' => ['features' => ['related' => true]],
        ]));

        $registry = new EntryWidgetRegistry(
            [
                'animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class),
                'animedb-shikimori:recommended' => $this->createStub(EntryWidgetInterface::class),
                'animedb-anilist:related' => $this->createStub(EntryWidgetInterface::class),
            ],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(
            [
                ['pluginId' => 'animedb-shikimori', 'widgetName' => 'recommended'],
                ['pluginId' => 'animedb-anilist', 'widgetName' => 'related'],
            ],
            $registry->findAllActive(),
        );
    }

    public function testListAllIncludesBothActiveAndInactiveWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => false, 'recommended' => true]],
        ]));

        $registry = new EntryWidgetRegistry(
            [
                'animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class),
                'animedb-shikimori:recommended' => $this->createStub(EntryWidgetInterface::class),
            ],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(
            [
                ['pluginId' => 'animedb-shikimori', 'widgetName' => 'related', 'active' => false],
                ['pluginId' => 'animedb-shikimori', 'widgetName' => 'recommended', 'active' => true],
            ],
            $registry->listAll(),
        );
    }

    public function testSetActiveTurnsAWidgetOnAndOff(): void
    {
        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class)],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'related', true);
        $this->assertNotNull($registry->find(new PluginId('animedb-shikimori'), 'related'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'related', false);
        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));
    }

    public function testSetActiveThrowsWhenEnablingAWidgetWouldExceedTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5', 'w6'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = $this->createStub(EntryWidgetInterface::class);
        }

        $registry = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->path));

        $this->assertSame(5, \count($registry->findAllActive()));

        $this->expectException(WidgetHardLimitExceededException::class);
        $registry->setActive(new PluginId('animedb-shikimori'), 'w6', true);
    }

    public function testSetActiveAllowsDisablingAWidgetEvenAtTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = $this->createStub(EntryWidgetInterface::class);
        }

        $registry = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->path));

        $registry->setActive(new PluginId('animedb-shikimori'), 'w1', false);

        $this->assertSame(4, \count($registry->findAllActive()));
    }

    public function testSetActiveAllowsReenablingAnAlreadyActiveWidgetAtTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = $this->createStub(EntryWidgetInterface::class);
        }

        $registry = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->path));

        $registry->setActive(new PluginId('animedb-shikimori'), 'w1', true);

        $this->assertSame(5, \count($registry->findAllActive()));
    }
}
