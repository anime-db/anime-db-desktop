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
use App\Event\InstalledPluginsChangedEvent;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

    public function testGetSubscribedEventsInvalidatesOnInstalledPluginsChanged(): void
    {
        $events = AvailableLocalesProvider::getSubscribedEvents();

        $this->assertSame('invalidate', $events[InstalledPluginsChangedEvent::class]);
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
     * for their own domain strings, but their manifest cannot declare `locales` (ManifestValidator
     * rejects it) — an enabled integration plugin must not contribute a new locale to the list.
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
     * Acceptance criterion (issue #453): repeated reads must not re-read the plugin index off
     * disk. Proven black-box, without mocking the final InstalledPluginsRegistry: the plugin
     * directory is mutated *after* the first all() call, and a second all() call — without
     * calling invalidate() — must still report the pre-mutation state, because it was served
     * from the in-memory cache rather than recomputed.
     */
    public function testAllCachesTheResultAcrossRepeatedCallsUntilInvalidated(): void
    {
        $registry = $this->registry();
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);

        $this->assertSame(['en', 'ru'], $provider->all());

        $this->writeTranslationManifest('animedb-french', ['fr']);
        $registry->reconcile();

        $this->assertSame(['en', 'ru'], $provider->all(), 'a repeated call must not observe the on-disk change');

        $provider->invalidate();

        $this->assertSame(['en', 'ru', 'fr'], $provider->all(), 'after invalidate(), the change must be visible');
    }

    /**
     * Acceptance criterion (issue #453): disabling or removing a plugin drops its locale from the
     * list without an application restart. This wires InstalledPluginsRegistry, PluginsConfigStore
     * and AvailableLocalesProvider together through a real EventDispatcher, exactly as
     * App\Kernel/services.yaml do in production, and never recreates the provider instance —
     * simulating the same object living for a whole (long-running) worker process.
     */
    public function testDisablingAPluginRemovesItsLocaleWithoutRecreatingTheProvider(): void
    {
        $eventDispatcher = new EventDispatcher();
        $configPath = $this->pluginsDir.'/plugins.json';
        $configStore = new PluginsConfigStore($configPath, $eventDispatcher);
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $configStore, new NullLogger(), eventDispatcher: $eventDispatcher);

        $this->writeTranslationManifest('animedb-french', ['fr']);
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);
        $eventDispatcher->addSubscriber($provider);

        $this->assertSame(['en', 'ru', 'fr'], $provider->all());

        $configStore->updatePluginSettings(new PluginId('animedb-french'), static function (array $settings): array {
            $settings['enabled'] = false;

            return $settings;
        });

        $this->assertSame(['en', 'ru'], $provider->all());
    }

    /**
     * Same scenario as above, but for removal (uninstall) instead of disable: reconcile() no
     * longer finding the plugin directory at all.
     */
    public function testRemovingAPluginRemovesItsLocaleWithoutRecreatingTheProvider(): void
    {
        $eventDispatcher = new EventDispatcher();
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger(), eventDispatcher: $eventDispatcher);

        $this->writeTranslationManifest('animedb-french', ['fr']);
        $registry->reconcile();

        $provider = new AvailableLocalesProvider($registry, ['en', 'ru']);
        $eventDispatcher->addSubscriber($provider);

        $this->assertSame(['en', 'ru', 'fr'], $provider->all());

        $this->removeDirectory($this->pluginsDir.'/animedb-french');
        $registry->reconcile();

        $this->assertSame(['en', 'ru'], $provider->all());
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
