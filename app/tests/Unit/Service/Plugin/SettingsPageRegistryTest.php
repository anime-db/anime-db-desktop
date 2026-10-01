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
use Symfony\Component\DependencyInjection\ServiceLocator;

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
        $locator = new ServiceLocator(['animedb-shikimori' => static fn (): SettingsPageInterface => $page]);
        $registry = new SettingsPageRegistry($this->installedPlugins, $locator);

        $this->assertSame($page, $registry->find(new PluginId('animedb-shikimori')));
    }

    public function testFindReturnsNullWhenNoSettingsPageIsRegisteredUnderThatPluginId(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->installedPlugins->reconcile();

        $registry = new SettingsPageRegistry($this->installedPlugins, new ServiceLocator([]));

        $this->assertNull($registry->find(new PluginId('animedb-shikimori')));
    }

    public function testFindReturnsNullWhenThePluginIsNotInstalled(): void
    {
        $page = $this->createStub(SettingsPageInterface::class);
        $locator = new ServiceLocator(['animedb-shikimori' => static fn (): SettingsPageInterface => $page]);
        $registry = new SettingsPageRegistry($this->installedPlugins, $locator);

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
        $locator = new ServiceLocator(['animedb-shikimori' => static fn (): SettingsPageInterface => $page]);
        $registry = new SettingsPageRegistry($this->installedPlugins, $locator);

        $this->assertNull($registry->find(new PluginId('animedb-shikimori')));
    }

    /**
     * Acceptance (issue #825): `find()` must resolve the one requested plugin's settings page
     * through the locator without ever constructing any other installed plugin's page service as
     * a side effect — a broken settings-page constructor in one plugin must not take down another
     * plugin's own settings page. Reverting `find()` to build the page from an injected iterable
     * (e.g. `iterator_to_array($pages)[$id] ?? null`) would construct every tagged page, including
     * `animedb-broken`'s, and trip the `RuntimeException` below.
     */
    public function testFindDoesNotInstantiateAnotherPluginsSettingsPageService(): void
    {
        $this->writeManifest('animedb-broken');
        $this->writeManifest('animedb-shikimori');
        $this->installedPlugins->reconcile();

        $workingPage = $this->createStub(SettingsPageInterface::class);
        $locator = new ServiceLocator([
            'animedb-broken' => static fn (): SettingsPageInterface => throw new \RuntimeException('Another plugin\'s settings page must not be instantiated.'),
            'animedb-shikimori' => static fn (): SettingsPageInterface => $workingPage,
        ]);

        $registry = new SettingsPageRegistry($this->installedPlugins, $locator);

        $this->assertSame($workingPage, $registry->find(new PluginId('animedb-shikimori')));
    }

    /**
     * Acceptance (issue #822): the settings sidebar calls this on every settings page load just to
     * list which plugins have a page, so it must never construct the page services themselves —
     * the locator's own factory throws, so a `get()` call on it (rather than `has()`) would fail.
     */
    public function testEnabledPluginIdsWithSettingsPageDoesNotInstantiateAnyPageService(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->installedPlugins->reconcile();

        $locator = new ServiceLocator([
            'animedb-shikimori' => static fn () => throw new \RuntimeException('The locator must only be queried with has(), never get().'),
        ]);

        $registry = new SettingsPageRegistry($this->installedPlugins, $locator);

        $this->assertSame(['animedb-shikimori'], $registry->enabledPluginIdsWithSettingsPage());
    }

    public function testEnabledPluginIdsWithSettingsPageExcludesAPluginWithoutAPageEvenWhenInstalledAndEnabled(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->installedPlugins->reconcile();

        $registry = new SettingsPageRegistry($this->installedPlugins, new ServiceLocator([]));

        $this->assertSame([], $registry->enabledPluginIdsWithSettingsPage());
    }

    public function testEnabledPluginIdsWithSettingsPageExcludesADisabledPluginEvenThoughItHasAPage(): void
    {
        $this->writeManifest('animedb-shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', json_encode([
            'animedb-shikimori' => ['enabled' => false],
        ]));
        $this->installedPlugins->reconcile();

        $locator = new ServiceLocator(['animedb-shikimori' => fn () => $this->createStub(SettingsPageInterface::class)]);
        $registry = new SettingsPageRegistry($this->installedPlugins, $locator);

        $this->assertSame([], $registry->enabledPluginIdsWithSettingsPage());
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
