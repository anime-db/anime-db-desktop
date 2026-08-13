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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SettingsPageRegistryTest extends TestCase
{
    private string $pluginsDir;
    private InstalledPluginsRegistry $installedPlugins;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-settings-page-registry-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);

        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    private function writeManifest(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    public function testFindReturnsTheRegisteredSettingsPageForAnInstalledEnabledPlugin(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $registry = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $this->assertSame($page, $registry->find(new PluginId('animedb-shikimori')));
    }

    public function testFindReturnsNullWhenNoSettingsPageIsRegisteredUnderThatPluginId(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->installedPlugins->reconcile();

        $registry = new SettingsPageRegistry([], $this->installedPlugins);

        $this->assertNull($registry->find(new PluginId('animedb-shikimori')));
    }

    public function testFindReturnsNullWhenThePluginIsNotInstalled(): void
    {
        $page = $this->createStub(SettingsPageInterface::class);
        $registry = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $this->assertNull($registry->find(new PluginId('animedb-shikimori')));
    }

    public function testFindReturnsNullWhenTheWholePluginIsDisabledEvenThoughAPageIsRegistered(): void
    {
        $this->writeManifest('animedb-shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', json_encode([
            'animedb-shikimori' => ['enabled' => false],
        ]));
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $registry = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $this->assertNull($registry->find(new PluginId('animedb-shikimori')));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
