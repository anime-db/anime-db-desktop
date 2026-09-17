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

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\Exception\NativeTranslationsOverlayException;
use App\Service\Translation\NativeTranslationsOverlayWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the wiring between {@see InstalledPluginsRegistry::reconcile()} and
 * {@see NativeTranslationsOverlayWriter} (issue #647) — the writer's own sanitizing/merging
 * behaviour is {@see \App\Tests\Unit\Service\Translation\NativeTranslationsOverlayWriterTest}'s
 * job; this file only checks that reconcile() actually calls it, inside the same operation, and
 * that a `safeMode` instance never gets to wipe the overlay with an empty plugin list.
 */
final class InstalledPluginsRegistryOverlayIntegrationTest extends TestCase
{
    private string $rootDir;
    private string $pluginsDir;
    private string $referenceDir;
    private string $overlayDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-installed-plugins-overlay-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        $this->referenceDir = $this->rootDir.'/native-translations';
        $this->overlayDir = $this->rootDir.'/overlay';
        mkdir($this->pluginsDir, recursive: true);
        mkdir($this->referenceDir, recursive: true);

        file_put_contents($this->referenceDir.'/en.json', (string) json_encode(['tray.quit' => 'Quit']));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testReconcileTriggersOverlayWriteAtItsEnd(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', 'kk', ['tray.quit' => 'Шығу']);

        $registry = $this->registryWithOverlayWriter();
        $registry->reconcile();

        $this->assertSame(['tray.quit' => 'Шығу'], $this->readOverlay('kk'));
    }

    public function testReconcileWithNoOverlayWriterConfiguredNeverTouchesTheOverlayDirectory(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', 'kk', ['tray.quit' => 'Шығу']);

        // The default used by every other test in this suite that constructs the registry
        // directly, with no writer given — reconcile() must remain a pure index rebuild for them.
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $this->assertDirectoryDoesNotExist($this->overlayDir);
    }

    public function testReconcileFailureFromTheOverlayWriterPropagatesOutOfReconcile(): void
    {
        // No reference catalog at all: the writer must throw before writing anything.
        unlink($this->referenceDir.'/en.json');
        $this->writeTranslationPlugin('lang-kazakh', 'kk', ['tray.quit' => 'Шығу']);

        $registry = $this->registryWithOverlayWriter();

        $this->expectException(NativeTranslationsOverlayException::class);
        $registry->reconcile();
    }

    /**
     * In safe mode, InstalledPluginsRegistry::enabled() reports no installed plugins at all
     * (issue #403) — if the overlay writer read from *that* instance, its own reconcile() (which,
     * in the real app, never runs — see InstalledPluginsRegistry's own docblock) would wipe every
     * overlay file. The writer instead holds its own, separately-injected registry, which here is
     * built with safeMode: false against the very same plugins directory: this is the concrete
     * stand-in for "the container's InstalledPluginsRegistry, not Kernel's pre-container one" the
     * issue requires — see NativeTranslationsOverlayWriter's own class docblock.
     */
    public function testSafeModeRegistryTriggeringReconcileStillWritesFromTheNonSafeModeRegistry(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', 'kk', ['tray.quit' => 'Шығу']);

        $nonSafeModeRegistry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $writer = new NativeTranslationsOverlayWriter($this->referenceDir, $this->overlayDir, $nonSafeModeRegistry, new NullLogger());

        $safeModeRegistry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            $this->configStore(),
            new NullLogger(),
            safeMode: true,
            overlayWriter: $writer,
        );

        $safeModeRegistry->reconcile();

        $this->assertSame(['tray.quit' => 'Шығу'], $this->readOverlay('kk'));
    }

    private function registryWithOverlayWriter(): InstalledPluginsRegistry
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $writer = new NativeTranslationsOverlayWriter($this->referenceDir, $this->overlayDir, $registry, new NullLogger());

        return new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger(), overlayWriter: $writer);
    }

    private function configStore(): PluginsConfigStore
    {
        return new PluginsConfigStore($this->pluginsDir.'/plugins.json');
    }

    /**
     * @param array<string, string> $nativeCatalog
     */
    private function writeTranslationPlugin(string $pluginId, string $locale, array $nativeCatalog): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations/native', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => [$locale],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        file_put_contents($dir.'/translations/native/'.$locale.'.json', (string) json_encode($nativeCatalog));
    }

    /**
     * @return array<string, string>|null
     */
    private function readOverlay(string $locale): ?array
    {
        $path = $this->overlayDir.'/'.$locale.'.json';
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
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
