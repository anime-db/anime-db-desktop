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

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ZipPluginInstallerTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';

    private string $rootDir;
    private string $pluginsDir;
    private string $fixturesDir;
    private InstalledPluginsRegistry $registry;

    protected function setUp(): void
    {
        // pluginsDir is nested one level inside rootDir (rather than being sys_get_temp_dir()
        // itself) so tests can tell "staged as a sibling of pluginsDir" apart from "staged
        // anywhere under the system temp directory" — see testStagingDirectoryIsSiblingOfPluginsDir().
        $this->rootDir = sys_get_temp_dir().'/anime-zip-installer-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->fixturesDir = sys_get_temp_dir().'/anime-zip-installer-fixtures-'.uniqid();
        mkdir($this->fixturesDir, recursive: true);

        $this->registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
        $this->removeDirectory($this->fixturesDir);
    }

    public function testInstallMovesUnpackedFilesAndReconcilesRegistry(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', '1.2.3'),
            'src/Plugin.php' => '<?php // plugin entry point',
        ]);

        $installer = $this->installer();
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);

        $targetDir = $this->pluginsDir.'/animedb-shikimori';
        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir.'/manifest.json');
        $this->assertFileExists($targetDir.'/src/Plugin.php');

        $installed = $this->registry->get(new PluginId('animedb-shikimori'));
        $this->assertNotNull($installed);
        $this->assertSame('1.2.3', $installed->manifest->version);

        $this->assertNoLeftoverTempDirectories();
    }

    public function testInstallRollsBackWhenManifestIsMissing(): void
    {
        $zipPath = $this->createZip([
            'src/Plugin.php' => '<?php // plugin entry point',
        ]);

        $installer = $this->installer();

        $this->expectException(InvalidInstalledPluginException::class);

        try {
            $installer->install($zipPath);
        } finally {
            $this->assertSame([], scandir($this->pluginsDir) === false ? [] : array_values(array_diff((array) scandir($this->pluginsDir), ['.', '..'])));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testInstallRollsBackWhenManifestJsonIsInvalid(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => '{not valid json',
        ]);

        $installer = $this->installer();

        $this->expectException(InvalidInstalledPluginException::class);

        try {
            $installer->install($zipPath);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), ['.', '..'])));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testInstallFailsWhenPluginIdIsAlreadyInstalled(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori'));
        $this->registry->reconcile();

        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0'),
        ]);

        $installer = $this->installer();

        $this->expectException(PluginAlreadyInstalledException::class);

        try {
            $installer->install($zipPath);
        } finally {
            // The pre-existing installation must be untouched by the failed install attempt.
            $installed = $this->registry->get(new PluginId('animedb-shikimori'));
            $this->assertNotNull($installed);
            $this->assertSame('1.0.0', $installed->manifest->version);
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testInstallRollsBackWhenMovingUnpackedDirectoryFails(): void
    {
        // A regular file sitting where the plugin directory should go makes rename() fail,
        // without being caught by the earlier is_dir()-based collision check.
        file_put_contents($this->pluginsDir.'/animedb-shikimori', 'not a directory');

        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
        ]);

        $installer = $this->installer();

        $this->expectException(PluginInstallException::class);

        try {
            $installer->install($zipPath);
        } finally {
            $this->assertFileExists($this->pluginsDir.'/animedb-shikimori');
            $this->assertSame('not a directory', file_get_contents($this->pluginsDir.'/animedb-shikimori'));
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testInstallFailsForCorruptZipArchive(): void
    {
        $zipPath = $this->fixturesDir.'/corrupt.zip';
        file_put_contents($zipPath, 'this is not a zip archive');

        $installer = $this->installer();

        $this->expectException(PluginInstallException::class);

        try {
            $installer->install($zipPath);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), ['.', '..'])));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    /**
     * Regression test for the EXDEV cross-filesystem rename() failure: the final move into
     * %app.plugins_dir% is only atomic if staging happens on the same volume. Actually mounting
     * a second filesystem is not portable in a unit test, so this asserts the location decision
     * that makes same-volume staging true by construction — a sibling of pluginsDir, not the
     * system temp directory.
     */
    public function testStagingDirectoryIsSiblingOfPluginsDir(): void
    {
        $installer = $this->installer();

        $method = new \ReflectionMethod($installer, 'stagingRootDir');

        $this->assertSame($this->rootDir.'/.plugin-install-tmp', $method->invoke($installer));
    }

    public function testInstallDescendsIntoSingleTopLevelWrapperDirectory(): void
    {
        // Packaging a directory directly (Explorer / `zip -r plugin.zip plugin/`) wraps
        // everything in one top-level directory instead of putting manifest.json at the root.
        $zipPath = $this->createZip([
            'animedb-shikimori/manifest.json' => $this->validManifestJson('animedb-shikimori'),
            'animedb-shikimori/src/Plugin.php' => '<?php // plugin entry point',
        ]);

        $installer = $this->installer();
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);

        $targetDir = $this->pluginsDir.'/animedb-shikimori';
        $this->assertFileExists($targetDir.'/manifest.json');
        $this->assertFileExists($targetDir.'/src/Plugin.php');
        $this->assertDirectoryDoesNotExist($targetDir.'/animedb-shikimori');

        $this->assertNoLeftoverTempDirectories();
    }

    public function testInstallRejectsZipWithPathTraversalEntry(): void
    {
        $zipPath = $this->fixturesDir.'/'.uniqid('plugin-', true).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $this->validManifestJson('animedb-shikimori'));
        $zip->addFromString('../escaped.txt', 'zip-slip payload');
        $zip->close();

        $installer = $this->installer();

        $this->expectException(PluginInstallException::class);

        try {
            $installer->install($zipPath);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), ['.', '..'])));
            $this->assertFileDoesNotExist(\dirname($this->pluginsDir).'/escaped.txt');
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testInstallSucceedsWhenCoreVersionSatisfiesRequirement(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', requireCore: '>='.self::CORE_VERSION),
        ]);

        $installer = $this->installer();
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    public function testInstallBlocksWhenRequiredCoreVersionIsHigherThanCurrent(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', requireCore: '>=99.0.0'),
        ]);

        $installer = $this->installer();

        try {
            $installer->install($zipPath);
            $this->fail('Expected IncompatiblePluginCoreVersionException to be thrown.');
        } catch (IncompatiblePluginCoreVersionException $exception) {
            $this->assertSame('>=99.0.0', $exception->requiredCore);
            $this->assertSame(self::CORE_VERSION, $exception->currentCore);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), ['.', '..'])));
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    private function installer(): ZipPluginInstaller
    {
        return new ZipPluginInstaller($this->pluginsDir, self::CORE_VERSION, $this->registry);
    }

    /**
     * @param array<string, string> $files relative path within the archive => file contents
     */
    private function createZip(array $files): string
    {
        $zipPath = $this->fixturesDir.'/'.uniqid('plugin-', true).'.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach ($files as $relativePath => $contents) {
            $zip->addFromString($relativePath, $contents);
        }
        $zip->close();

        return $zipPath;
    }

    private function validManifestJson(string $pluginId, string $version = '1.0.0', string $requireCore = '>=2.0.0'): string
    {
        return (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => $requireCore, 'php' => '>=8.2'],
        ]);
    }

    /**
     * Confirms the installer never leaves its own staging directories behind, regardless of
     * whether install() succeeded or rolled back.
     */
    private function assertNoLeftoverTempDirectories(): void
    {
        $stagingRoot = $this->rootDir.'/.plugin-install-tmp';
        $entries = is_dir($stagingRoot) ? scandir($stagingRoot) : [];
        $leftovers = array_values(array_diff(false === $entries ? [] : $entries, ['.', '..']));

        $this->assertSame([], $leftovers);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
