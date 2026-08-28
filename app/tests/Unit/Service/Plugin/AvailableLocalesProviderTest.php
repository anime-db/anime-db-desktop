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

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AvailableLocalesProviderTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-available-locales-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testAllReturnsOnlyCoreLocalesWhenNoTranslationPluginIsEnabled(): void
    {
        $registry = $this->registry();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru'], $provider->all());
    }

    public function testAllMergesLocalesDeclaredByEnabledTranslationPlugins(): void
    {
        $this->writeTranslationManifest('animedb-french', ['fr']);
        $registry = $this->registry();
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru', 'fr'], $provider->all());
    }

    public function testAllIgnoresLocalesFromDisabledTranslationPlugins(): void
    {
        $this->writeTranslationManifest('animedb-french', ['fr']);
        $configPath = $this->pluginsDir.'/plugins.json';
        file_put_contents($configPath, json_encode(['animedb-french' => ['enabled' => false]]));

        $registry = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($configPath), new NullLogger());
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru'], $provider->all());
    }

    /**
     * Integration plugins get a translations/ directory of their own (PluginLoader::translationPaths())
     * for their own domain strings. The manifest field `locales` is allowed on an integration manifest
     * too (ManifestValidator accepts it there — it declares the parser must not reject a manifest
     * merely for carrying the field), but must still be ignored here: it names locales for the
     * plugin's own domain, not the `messages` domain the language switcher reads from.
     */
    public function testAllIgnoresIntegrationPluginsEntirely(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'locales' => ['fr'],
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $registry = $this->registry();
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru'], $provider->all());
    }

    public function testAllDeduplicatesALocaleDeclaredByBothCoreAndAPlugin(): void
    {
        $this->writeTranslationManifest('animedb-ru-extra', ['ru']);
        $registry = $this->registry();
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru'], $provider->all());
    }

    /**
     * Acceptance criterion (issue #453): a change to the installed/enabled plugin set must be
     * visible on the very next call, with no cache to invalidate. Proven black-box: the plugin
     * directory is mutated *after* the first all() call, and the second call — on the very same
     * provider instance, with no event or invalidate() involved — already reports the change.
     */
    public function testAllObservesOnDiskChangesOnTheNextCallWithNoCacheToInvalidate(): void
    {
        $registry = $this->registry();
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru'], $provider->all());

        $this->writeTranslationManifest('animedb-french', ['fr']);
        $registry->reconcile();

        $this->assertSame(['en', 'ru', 'fr'], $provider->all());
    }

    /**
     * Acceptance criterion (issue #453): disabling a plugin drops its locale from the list on
     * every FrankenPHP worker, not just the one that handled the disable request. Modeled here
     * with two independent AvailableLocalesProvider/InstalledPluginsRegistry instances sharing
     * the same on-disk plugins directory and plugins.json — one instance mutates, a *second,
     * unrelated* instance (standing in for another worker's own isolated memory) must see the
     * change too, without any event passed between them.
     */
    public function testDisablingAPluginRemovesItsLocaleForAnIndependentProviderInstance(): void
    {
        $configStore = $this->configStore();
        $writerRegistry = new InstalledPluginsRegistry($this->pluginsDir, $configStore, new NullLogger());

        $this->writeTranslationManifest('animedb-french', ['fr']);
        $writerRegistry->reconcile();

        $otherWorkerProvider = new AvailableLocalesProvider($this->registry(), ['en', 'ru']);

        $this->assertSame(['en', 'ru', 'fr'], $otherWorkerProvider->all());

        $configStore->updatePluginSettings(new PluginId('animedb-french'), static function (array $settings): array {
            $settings['enabled'] = false;

            return $settings;
        });

        $this->assertSame(['en', 'ru'], $otherWorkerProvider->all());
    }

    /**
     * Same scenario as above, but for removal (uninstall) instead of disable: reconcile() no
     * longer finding the plugin directory at all.
     */
    public function testRemovingAPluginRemovesItsLocaleForAnIndependentProviderInstance(): void
    {
        $writerRegistry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());

        $this->writeTranslationManifest('animedb-french', ['fr']);
        $writerRegistry->reconcile();

        $otherWorkerProvider = new AvailableLocalesProvider($this->registry(), ['en', 'ru']);

        $this->assertSame(['en', 'ru', 'fr'], $otherWorkerProvider->all());

        $this->removeDirectory($this->pluginsDir.'/animedb-french');
        $writerRegistry->reconcile();

        $this->assertSame(['en', 'ru'], $otherWorkerProvider->all());
    }

    private function registry(): InstalledPluginsRegistry
    {
        return new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
    }

    private function configStore(): PluginsConfigStore
    {
        return new PluginsConfigStore($this->pluginsDir.'/plugins.json');
    }

    /**
     * @param list<string> $locales
     */
    private function writeTranslationManifest(string $pluginId, array $locales): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => $locales,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        mkdir($dir.'/translations', recursive: true);
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
