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
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\NativeTranslationsOverlayWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PluginRemoverTest extends TestCase
{
    private string $rootDir;
    private string $pluginsDir;
    private InstalledPluginsRegistry $registry;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-plugin-remover-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testRemoveDeletesTheInstalledPluginDirectoryAndResyncsTheRegistry(): void
    {
        $this->installFixture('animedb-shikimori');
        $this->registry->reconcile();
        $this->assertTrue($this->registry->has(new PluginId('animedb-shikimori')));

        (new PluginRemover($this->registry))->remove(new PluginId('animedb-shikimori'));

        $this->assertDirectoryDoesNotExist($this->pluginsDir.'/animedb-shikimori');
        $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
    }

    public function testRemoveLeavesOtherInstalledPluginsUntouched(): void
    {
        $this->installFixture('animedb-shikimori');
        $this->installFixture('animedb-anilist');
        $this->registry->reconcile();

        (new PluginRemover($this->registry))->remove(new PluginId('animedb-shikimori'));

        $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
        $this->assertTrue($this->registry->has(new PluginId('animedb-anilist')));
        $this->assertDirectoryExists($this->pluginsDir.'/animedb-anilist');
    }

    public function testRemoveIsANoOpWhenThePluginIsNotInstalled(): void
    {
        $this->registry->reconcile();

        (new PluginRemover($this->registry))->remove(new PluginId('animedb-unknown'));

        $this->assertFalse($this->registry->has(new PluginId('animedb-unknown')));
    }

    /**
     * Issue #647 acceptance: removing the last enabled `translation` plugin covering a locale
     * removes that locale's overlay file — PluginRemover::remove() -> reconcile() -> the writer,
     * the same single funnel {@see ZipPluginInstallerTest} exercises for install.
     */
    public function testRemoveDeletesTheOverlayLocaleThePluginNoLongerCovers(): void
    {
        $referenceDir = $this->rootDir.'/native-translations';
        $overlayDir = $this->rootDir.'/overlay';
        mkdir($referenceDir, recursive: true);
        file_put_contents($referenceDir.'/en.json', (string) json_encode(['tray.quit' => 'Quit']));

        $dir = $this->pluginsDir.'/lang-kazakh';
        mkdir($dir.'/translations/native', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'lang-kazakh',
            'name' => 'Lang kazakh',
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['kk'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        file_put_contents($dir.'/translations/native/kk.json', (string) json_encode(['tray.quit' => 'Шығу']));

        $writer = new NativeTranslationsOverlayWriter($referenceDir, $overlayDir, $this->registry, new NullLogger());
        $registryWithWriter = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
            overlayWriter: $writer,
        );
        $registryWithWriter->reconcile();
        $this->assertFileExists($overlayDir.'/kk.json');

        (new PluginRemover($registryWithWriter))->remove(new PluginId('lang-kazakh'));

        $this->assertFileDoesNotExist($overlayDir.'/kk.json');
    }

    private function installFixture(string $pluginId): void
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
