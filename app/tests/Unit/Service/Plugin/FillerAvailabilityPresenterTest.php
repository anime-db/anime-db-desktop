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
use App\Service\Plugin\FillerAvailabilityPresenter;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pins describeUnavailable()'s route choice for each of the three
 * {@see \App\Service\Plugin\FillerAvailabilityState} cases — a mutation swapping
 * 'settings_plugin_page' for 'settings_plugins_index' in the DisabledWithSettingsPage branch
 * (issue #833 review) survived every other test in the suite because nothing asserted on the
 * generated URL itself, only on 'kind'.
 */
final class FillerAvailabilityPresenterTest extends TestCase
{
    private string $path;
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-filler-availability-test-'.uniqid().'.json';
        $this->pluginsDir = sys_get_temp_dir().'/anime-filler-availability-dir-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeDirectory($this->pluginsDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function stubUrlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params = []): string => '/'.$name.(($params !== []) ? '?'.http_build_query($params) : ''),
        );

        return $urlGenerator;
    }

    public function testDescribeUnavailableLinksToTheMarketWhenNoFillerPluginIsInstalled(): void
    {
        $registry = new FillerRegistry([], new PluginsConfigStore($this->path));
        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([]));

        $presenter = new FillerAvailabilityPresenter($registry, $installedPlugins, $settingsPages, $this->stubUrlGenerator());
        $result = $presenter->describeUnavailable();

        $this->assertSame('not_installed', $result['kind']);
        $this->assertSame('/settings_market_index', $result['url']);
    }

    public function testDescribeUnavailableLinksToThePluginsListWhenTheWholePluginIsDisabled(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['enabled' => false],
        ]));

        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $installedPlugins->reconcile();

        $registry = new FillerRegistry([], new PluginsConfigStore($this->path));
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([]));

        $presenter = new FillerAvailabilityPresenter($registry, $installedPlugins, $settingsPages, $this->stubUrlGenerator());
        $result = $presenter->describeUnavailable();

        $this->assertSame('disabled', $result['kind']);
        $this->assertSame('/settings_plugins_index', $result['url']);
    }

    public function testDescribeUnavailableLinksToThePluginsOwnSettingsPageWhenOnlyTheFillerFeatureIsOff(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $installedPlugins->reconcile();

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = new FillerRegistry([], new PluginsConfigStore($this->path));
        $settingsPage = $this->createStub(SettingsPageInterface::class);
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([
            'animedb-shikimori' => static fn (): SettingsPageInterface => $settingsPage,
        ]));

        $presenter = new FillerAvailabilityPresenter($registry, $installedPlugins, $settingsPages, $this->stubUrlGenerator());
        $result = $presenter->describeUnavailable();

        $this->assertSame('disabled', $result['kind']);
        $this->assertSame('/settings_plugin_page?pluginId=animedb-shikimori', $result['url']);
    }
}
