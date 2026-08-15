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
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginCacheWarmupException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginNotInstalledException;
use App\Service\Plugin\Exception\PluginSyntaxErrorException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginCacheWarmerInterface;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\WsPublisher;
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

    public function testInstallSucceedsWhenAllPhpFilesAreSyntacticallyValid(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
            'src/Plugin.php' => "<?php\n\nfinal class Plugin\n{\n}\n",
            'src/Helper.php' => "<?php\n\nfunction helper(): void\n{\n}\n",
        ]);

        $installer = $this->installer();
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
        $this->assertNoLeftoverTempDirectories();
    }

    public function testInstallBlocksWhenPluginContainsPhpSyntaxError(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
            'src/Plugin.php' => "<?php\n\nfinal class Plugin\n{\n", // unclosed class body
        ]);

        $installer = $this->installer();

        try {
            $installer->install($zipPath);
            $this->fail('Expected PluginSyntaxErrorException to be thrown.');
        } catch (PluginSyntaxErrorException $exception) {
            $this->assertCount(1, $exception->errors);
            $this->assertSame('src/Plugin.php', $exception->errors[0]->relativePath);
            $this->assertNotSame('', $exception->errors[0]->message);
            $this->assertStringContainsString('on line', $exception->errors[0]->message);
            $this->assertStringNotContainsString('.plugin-install-tmp', $exception->errors[0]->message);
            $this->assertStringContainsString('src/Plugin.php', $exception->getMessage());
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), ['.', '..'])));
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testInstallSkipsSyntaxLintWhenTrusted(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
            'src/Plugin.php' => "<?php\n\nfinal class Plugin\n{\n", // unclosed class body
        ]);

        $installer = $this->installer();
        $pluginId = $installer->install($zipPath, trusted: true);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
        $this->assertTrue($this->registry->has(new PluginId('animedb-shikimori')));
        $this->assertNoLeftoverTempDirectories();
    }

    public function testInstallRollsBackAndResyncsRegistryWhenCacheWarmupFails(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
        ]);

        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->never())->method('publish');

        $installer = new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            new class implements PluginCacheWarmerInterface {
                public function warmUp(): void
                {
                    throw new PluginCacheWarmupException('boom');
                }
            },
            $wsPublisher,
        );

        $this->expectException(PluginCacheWarmupException::class);

        try {
            $installer->install($zipPath);
        } finally {
            // The plugin directory moved into place by install() before the warm-up ran must not
            // survive a failed warm-up, and the registry's index must be re-synced afterwards so
            // it does not keep pointing at the now-removed directory.
            $this->assertDirectoryDoesNotExist($this->pluginsDir.'/animedb-shikimori');
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    /**
     * The native supervisor (native/supervisor/index.js) only learns a plugin needs to be made
     * live via this event (issue #411) — a successful install that never publishes it would leave
     * the plugin installed but permanently inactive until the next full app restart.
     */
    public function testInstallPublishesWorkersReloadEventAfterSuccessfulCacheWarmup(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
        ]);

        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ZipPluginInstaller::WORKERS_RELOAD_EVENT, ['pluginId' => 'animedb-shikimori']);

        $installer = new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            $this->cacheWarmer(),
            $wsPublisher,
        );

        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    /**
     * A failure to publish {@see ZipPluginInstaller::WORKERS_RELOAD_EVENT} (e.g. a transient
     * SQLite write error in {@see WsPublisher}) must not undo an install that already succeeded —
     * it was moved into place, the registry was re-synced and the isolated cache warm-up passed
     * before publish() ever runs. Degrading to "installed but not yet live" (activated on the next
     * full app restart) is the intended fallback, not a full rollback.
     */
    public function testInstallSucceedsWhenPublishingWorkersReloadEventFails(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
        ]);

        $wsPublisher = $this->createStub(WsPublisher::class);
        $wsPublisher->method('publish')->willThrowException(new \RuntimeException('queue.db is locked'));

        $installer = new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            $this->cacheWarmer(),
            $wsPublisher,
        );

        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
        $this->assertDirectoryExists($this->pluginsDir.'/animedb-shikimori');
        $this->assertTrue($this->registry->has(new PluginId('animedb-shikimori')));
    }

    public function testUpdateSwapsInTheNewVersionAndReconcilesRegistry(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        file_put_contents($this->pluginsDir.'/animedb-shikimori/old-only-file.txt', 'left over from v1');
        $this->registry->reconcile();

        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0'),
            'src/Plugin.php' => '<?php // v2 entry point',
        ]);

        $pluginId = $this->installer()->update($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);

        $installed = $this->registry->get(new PluginId('animedb-shikimori'));
        $this->assertNotNull($installed);
        $this->assertSame('2.0.0', $installed->manifest->version);
        $this->assertFileExists($this->pluginsDir.'/animedb-shikimori/src/Plugin.php');
        $this->assertFileDoesNotExist($this->pluginsDir.'/animedb-shikimori/old-only-file.txt');
        $this->assertNoLeftoverTempDirectories();
    }

    public function testUpdatePreservesPluginSettingsAcrossTheDirectorySwap(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        $this->registry->reconcile();

        $configStore = new PluginsConfigStore($this->pluginsDir.'/plugins.json');
        $configStore->updatePluginSettings(new PluginId('animedb-shikimori'), static fn (array $settings): array => [
            ...$settings,
            'settings' => ['token' => 'secret-oauth-token'],
        ]);

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0')]);

        $this->installer()->update($zipPath);

        $this->assertSame(['token' => 'secret-oauth-token'], $configStore->getSettingsStorePayload(new PluginId('animedb-shikimori')));
    }

    public function testUpdateFailsWhenPluginIdIsNotInstalled(): void
    {
        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0')]);

        $installer = $this->installer();

        $this->expectException(PluginNotInstalledException::class);

        try {
            $installer->update($zipPath);
        } finally {
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    /**
     * The core rollback guarantee behind issue #224: a failed isolated warm-up of the new version
     * must restore the previous, still-working version rather than leaving the plugin directory
     * empty or half-written — and must leave the plugin's settings untouched throughout, since
     * they live in plugins.json rather than the swapped directory.
     */
    public function testUpdateRestoresThePreviousVersionAndPreservesSettingsWhenCacheWarmupFails(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        file_put_contents($this->pluginsDir.'/animedb-shikimori/v1-only-file.txt', 'v1 marker');
        $this->registry->reconcile();

        $configStore = new PluginsConfigStore($this->pluginsDir.'/plugins.json');
        $configStore->updatePluginSettings(new PluginId('animedb-shikimori'), static fn (array $settings): array => [
            ...$settings,
            'settings' => ['token' => 'secret-oauth-token'],
        ]);

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0')]);

        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->never())->method('publish');

        $installer = new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            new class implements PluginCacheWarmerInterface {
                public function warmUp(): void
                {
                    throw new PluginCacheWarmupException('boom');
                }
            },
            $wsPublisher,
        );

        $this->expectException(PluginCacheWarmupException::class);

        try {
            $installer->update($zipPath);
        } finally {
            $installed = $this->registry->get(new PluginId('animedb-shikimori'));
            $this->assertNotNull($installed);
            $this->assertSame('1.0.0', $installed->manifest->version);
            $this->assertFileExists($this->pluginsDir.'/animedb-shikimori/v1-only-file.txt');
            $this->assertSame(
                ['token' => 'secret-oauth-token'],
                $configStore->getSettingsStorePayload(new PluginId('animedb-shikimori')),
            );
            $this->assertNoLeftoverTempDirectories();
        }
    }

    /**
     * The native supervisor only learns an updated plugin needs to be made live via this event
     * (issue #224, same mechanism as {@see testInstallPublishesWorkersReloadEventAfterSuccessfulCacheWarmup()}).
     */
    public function testUpdatePublishesWorkersReloadEventAfterSuccessfulCacheWarmup(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        $this->registry->reconcile();

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0')]);

        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ZipPluginInstaller::WORKERS_RELOAD_EVENT, ['pluginId' => 'animedb-shikimori']);

        $installer = new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            $this->cacheWarmer(),
            $wsPublisher,
        );

        $pluginId = $installer->update($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
        $installed = $this->registry->get(new PluginId('animedb-shikimori'));
        $this->assertNotNull($installed);
        $this->assertSame('2.0.0', $installed->manifest->version);
        $this->assertNoLeftoverTempDirectories();
    }

    public function testUpdateSkipsSyntaxLintWhenTrusted(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        $this->registry->reconcile();

        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0'),
            'src/Plugin.php' => "<?php\n\nfinal class Plugin\n{\n", // unclosed class body
        ]);

        $pluginId = $this->installer()->update($zipPath, trusted: true);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
        $installed = $this->registry->get(new PluginId('animedb-shikimori'));
        $this->assertNotNull($installed);
        $this->assertSame('2.0.0', $installed->manifest->version);
        $this->assertNoLeftoverTempDirectories();
    }

    public function testUpdateBlocksWhenRequiredCoreVersionIsHigherThanCurrent(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        $this->registry->reconcile();

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0', requireCore: '>=99.0.0')]);

        $installer = $this->installer();

        try {
            $installer->update($zipPath);
            $this->fail('Expected IncompatiblePluginCoreVersionException to be thrown.');
        } catch (IncompatiblePluginCoreVersionException $exception) {
            $this->assertSame('>=99.0.0', $exception->requiredCore);
            $this->assertSame(self::CORE_VERSION, $exception->currentCore);
        } finally {
            $installed = $this->registry->get(new PluginId('animedb-shikimori'));
            $this->assertNotNull($installed);
            $this->assertSame('1.0.0', $installed->manifest->version);
            $this->assertNoLeftoverTempDirectories();
        }
    }

    private function installer(?WsPublisher $wsPublisher = null): ZipPluginInstaller
    {
        return new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            $this->cacheWarmer(),
            $wsPublisher ?? $this->createStub(WsPublisher::class),
        );
    }

    /**
     * A real {@see PluginCacheWarmer}, same as {@see installer()} builds a real registry rather
     * than a fake — its subprocess spawn is a thin wrapper around `bin/console cache:warmup`
     * against this very app, so exercising it for real is the only way to catch a broken
     * PHP-binary/console-path resolution the way the earlier `php -l` linting is already
     * exercised for real in these tests.
     */
    private function cacheWarmer(): PluginCacheWarmerInterface
    {
        return new PluginCacheWarmer($this->pluginsDir, \dirname(__DIR__, 4), new NullLogger());
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
        $leftovers = array_values(array_diff($entries === false ? [] : $entries, ['.', '..']));

        $this->assertSame([], $leftovers);
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
