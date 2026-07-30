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

use AnimeDb\PluginContracts\SearchByPluginInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\DefaultSearchPluginRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class DefaultSearchPluginRegistryTest extends TestCase
{
    private string $pluginsConfigPath;
    private string $appConfigPath;

    protected function setUp(): void
    {
        $this->pluginsConfigPath = sys_get_temp_dir().'/anime-default-search-plugins-'.uniqid().'.json';
        $this->appConfigPath = sys_get_temp_dir().'/anime-default-search-config-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginsConfigPath, $this->pluginsConfigPath.'.tmp', $this->pluginsConfigPath.'.lock', $this->appConfigPath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createSearch(): SearchByPluginInterface
    {
        return $this->createStub(SearchByPluginInterface::class);
    }

    /** @param iterable<string, SearchByPluginInterface> $plugins */
    private function registry(iterable $plugins): DefaultSearchPluginRegistry
    {
        return new DefaultSearchPluginRegistry(
            $plugins,
            new PluginsConfigStore($this->pluginsConfigPath),
            new AppSettingsProvider($this->appConfigPath),
        );
    }

    public function testGetDefaultReturnsNullWhenNoSearchPluginIsInstalled(): void
    {
        $registry = $this->registry([]);

        $this->assertNull($registry->getDefault());
    }

    public function testGetDefaultFallsBackToTheFirstAvailablePluginWhenNoneIsConfigured(): void
    {
        $registry = $this->registry([
            'animedb-shikimori' => $this->createSearch(),
            'animedb-anilist' => $this->createSearch(),
        ]);

        $this->assertSame('animedb-shikimori', (string) $registry->getDefault());
    }

    public function testGetDefaultReturnsTheConfiguredPluginWhenStillAvailable(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-anilist']));

        $registry = $this->registry([
            'animedb-shikimori' => $this->createSearch(),
            'animedb-anilist' => $this->createSearch(),
        ]);

        $this->assertSame('animedb-anilist', (string) $registry->getDefault());
    }

    public function testGetDefaultCascadesToNextPluginWhenConfiguredOneWasRemoved(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));

        $registry = $this->registry([
            'animedb-anilist' => $this->createSearch(),
        ]);

        $default = $registry->getDefault();

        $this->assertSame('animedb-anilist', (string) $default);
        // The cascade is persisted, not just returned in-memory.
        $this->assertSame(
            'animedb-anilist',
            json_decode((string) file_get_contents($this->appConfigPath), true)['defaultSearchPluginId'],
        );
    }

    public function testGetDefaultCascadesToNullAndClearsSettingWhenNoSearchPluginIsLeft(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));

        $registry = $this->registry([]);

        $this->assertNull($registry->getDefault());
        $this->assertNull(json_decode((string) file_get_contents($this->appConfigPath), true)['defaultSearchPluginId']);
    }

    public function testGetDefaultCascadesWhenConfiguredPluginsFillerFeatureIsDisabled(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));
        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = $this->registry([
            'animedb-shikimori' => $this->createSearch(),
            'animedb-anilist' => $this->createSearch(),
        ]);

        $this->assertSame('animedb-anilist', (string) $registry->getDefault());
    }

    public function testSetDefaultPersistsTheGivenPluginId(): void
    {
        $registry = $this->registry([
            'animedb-anilist' => $this->createSearch(),
        ]);

        $registry->setDefault(new PluginId('animedb-anilist'));

        $this->assertSame('animedb-anilist', (string) $registry->getDefault());
    }

    public function testSetDefaultWithNullClearsTheSetting(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-anilist']));

        $registry = $this->registry([
            'animedb-anilist' => $this->createSearch(),
        ]);

        $registry->setDefault(null);

        $this->assertNull(json_decode((string) file_get_contents($this->appConfigPath), true)['defaultSearchPluginId']);
    }
}
