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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\FillerInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Filler\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class FillerRegistryTest extends TestCase
{
    private string $pluginsConfigPath;

    protected function setUp(): void
    {
        $this->pluginsConfigPath = sys_get_temp_dir().'/anime-filler-registry-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginsConfigPath, $this->pluginsConfigPath.'.tmp', $this->pluginsConfigPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testGetReturnsNullWhenNoPluginIsRegisteredForId(): void
    {
        $registry = new FillerRegistry([], new PluginsConfigStore($this->pluginsConfigPath));

        $this->assertNull($registry->get(new PluginId('animedb-shikimori')));
    }

    public function testGetReturnsRegisteredPluginById(): void
    {
        $filler = $this->createStub(FillerInterface::class);

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $filler],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $this->assertSame($filler, $registry->get(new PluginId('animedb-shikimori')));
    }

    public function testGetIgnoresPluginsRegisteredUnderAnotherId(): void
    {
        $filler = $this->createStub(FillerInterface::class);

        $registry = new FillerRegistry(
            ['animedb-anilist' => $filler],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $this->assertNull($registry->get(new PluginId('animedb-shikimori')));
    }

    public function testGetReturnsNullWhenPluginIsExplicitlySwitchedOff(): void
    {
        $store = new PluginsConfigStore($this->pluginsConfigPath);
        $pluginId = new PluginId('animedb-shikimori');
        $store->updatePluginSettings($pluginId, static fn (array $settings): array => [...$settings, 'active' => false]);

        $filler = $this->createStub(FillerInterface::class);
        $registry = new FillerRegistry(['animedb-shikimori' => $filler], $store);

        $this->assertNull($registry->get($pluginId));
    }

    public function testGetReturnsPluginWhenNoActiveFlagIsStoredYet(): void
    {
        $filler = $this->createStub(FillerInterface::class);

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $filler],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $this->assertSame($filler, $registry->get(new PluginId('animedb-shikimori')));
    }
}
