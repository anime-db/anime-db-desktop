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

use AnimeDb\PluginContracts\Manifest\Manifest;
use AnimeDb\PluginContracts\Manifest\ManifestRequirements;
use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\IncompatiblePluginContractsVersionException;
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
use App\Service\Translation\NativeTranslationsOverlayWriter;
use App\Service\WsPublisher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ZipPluginInstallerTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';

    /**
     * {@see InstalledPluginsRegistry::synchronized()} leaves a `.plugins.lock`
     * file behind in $pluginsDir on first use, the same permanent-sentinel pattern
     * {@see PluginsConfigStore} already uses for `plugins.json.lock` — a
     * "nothing left behind" assertion below is about plugin directories, not this lock file.
     *
     * @var list<string>
     */
    private const array PLUGINS_DIR_HOUSEKEEPING_ENTRIES = ['.', '..', '.plugins.lock'];

    private string $rootDir;
    private string $pluginsDir;
    private string $fixturesDir;
    private InstalledPluginsRegistry $registry;

    /**
     * Extra root directories created by {@see self::installerWithOverlayWriter()}, one per call —
     * that helper needs its own plugins/reference/overlay directory triplet, separate from
     * $this->rootDir/$this->pluginsDir every other test in this file shares.
     *
     * @var list<string>
     */
    private array $overlayTestRootDirs = [];

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
        foreach ($this->overlayTestRootDirs as $dir) {
            $this->removeDirectory($dir);
        }
    }

    #[Group('runtime-parity')]
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
            $this->assertSame([], scandir($this->pluginsDir) === false ? [] : array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
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
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
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
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
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

    #[Group('runtime-parity')]
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
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
            $this->assertFileDoesNotExist(\dirname($this->pluginsDir).'/escaped.txt');
            $this->assertNoLeftoverTempDirectories();
        }
    }

    #[Group('runtime-parity')]
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
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    #[Group('runtime-parity')]
    public function testInstallSucceedsWhenPluginContractsRequirementIsSatisfied(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', requirePluginContracts: '^0.15'),
        ]);

        $installer = $this->installer(pluginContractsVersion: 'v0.15.0');
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    public function testInstallBlocksWhenPluginContractsRequirementIsNotSatisfied(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', requirePluginContracts: '^0.16'),
        ]);

        $installer = $this->installer(pluginContractsVersion: 'v0.15.0');

        try {
            $installer->install($zipPath);
            $this->fail('Expected IncompatiblePluginContractsVersionException to be thrown.');
        } catch (IncompatiblePluginContractsVersionException $exception) {
            $this->assertSame('^0.16', $exception->requiredPluginContracts);
            $this->assertSame('v0.15.0', $exception->installedPluginContracts);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    #[Group('runtime-parity')]
    public function testInstallSucceedsWhenManifestOmitsPluginContractsRequirement(): void
    {
        // No 'plugin-contracts' key at all — validManifestJson()'s default. An unsatisfiable
        // installed version would block install() if the check ran anyway; it must not.
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
        ]);

        $installer = $this->installer(pluginContractsVersion: 'v0.1.0');
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    #[Group('runtime-parity')]
    public function testInstallSucceedsWhenInstalledPluginContractsVersionIsUnknown(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', requirePluginContracts: '^0.16'),
        ]);

        // installer()'s default omits pluginContractsVersion entirely (null) — the fail-open path.
        $installer = $this->installer();
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    /**
     * A `null` `$coreVersion` (this app build could not determine its own core version, issue
     * #565) must fail open the same way an unknown `pluginContractsVersion` does — this manifest
     * requires a core version no installed build could ever satisfy, so a working core-version
     * check would reject it.
     */
    #[Group('runtime-parity')]
    public function testInstallSucceedsWhenInstalledCoreVersionIsUnknown(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', requireCore: '>=99.0.0'),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('Unable to determine the installed core version'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'animedb-shikimori'),
        );

        $installer = $this->installer(coreVersion: null, logger: $logger);
        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    /**
     * `ManifestValidator` already rejects a `require.plugin-contracts` that does not parse as a
     * version constraint, at manifest-parse time — before {@see ZipPluginInstaller} ever sees a
     * {@see Manifest} object — so this fail-open branch cannot be reached through a real ZIP
     * install. It still exists as defence in depth, symmetric with
     * {@see InstalledPluginsRegistry}'s own copy of the same check (which *can* see one, via an
     * index written by an older, looser validator) — exercised directly here via the private
     * method, the same technique {@see testStagingDirectoryIsSiblingOfPluginsDir()} uses.
     */
    public function testAssertPluginContractsCompatibleFailsOpenWhenConstraintIsUnparsable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('Unable to parse'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'animedb-shikimori'),
        );

        $installer = $this->installer(pluginContractsVersion: 'v0.15.0', logger: $logger);

        $manifest = new Manifest(
            id: 'animedb-shikimori',
            name: 'Shikimori',
            version: '1.0.0',
            type: PluginType::Integration,
            require: new ManifestRequirements(core: '>=2.0.0', php: '>=8.2', pluginContracts: 'not-a-valid-constraint'),
            features: ['filler' => true],
        );

        $method = new \ReflectionMethod($installer, 'assertPluginContractsCompatible');

        // Must not throw — the unparsable constraint is logged and treated as satisfied.
        $method->invoke($installer, $manifest);
    }

    #[Group('runtime-parity')]
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

    #[Group('runtime-parity')]
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
            $this->assertSame([], array_values(array_diff((array) scandir($this->pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
            $this->assertNoLeftoverTempDirectories();
        }
    }

    #[Group('runtime-parity')]
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
    #[Group('runtime-parity')]
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
    #[Group('runtime-parity')]
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

    #[Group('runtime-parity')]
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

    public function testUpdateLeavesThePluginCacheDirectoryUntouched(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        $this->registry->reconcile();
        $cacheDir = $this->rootDir.'/plugin-cache/animedb-shikimori';
        mkdir($cacheDir, recursive: true);
        file_put_contents($cacheDir.'/dump.bin', 'cached');

        $this->installer()->update($this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0'),
            'src/Plugin.php' => '<?php // v2 entry point',
        ]));

        $this->assertSame('cached', file_get_contents($cacheDir.'/dump.bin'));
    }

    #[Group('runtime-parity')]
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
     * Regression test for issue #420 defect C: if restoring the backup itself fails (e.g. a file
     * inside it is locked, simulated here by deleting the backup out from under the installer
     * before it gets a chance to move it back), the exception update() throws must still be the
     * *original* failure (the cache warm-up here), not an unrelated filesystem error masking it —
     * and the restore failure itself must be logged, not silently swallowed.
     */
    public function testUpdatePreservesTheOriginalExceptionWhenRestoringTheBackupFails(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', $this->validManifestJson('animedb-shikimori', '1.0.0'));
        $this->registry->reconcile();

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', '2.0.0')]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error')->with(
            $this->stringContains('Failed to restore the previous plugin version'),
            $this->anything(),
        );

        $installer = new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            new class($this->rootDir) implements PluginCacheWarmerInterface {
                public function __construct(private readonly string $rootDir)
                {
                }

                public function warmUp(): void
                {
                    $stagingRoot = $this->rootDir.'/.plugin-install-tmp';
                    $entries = scandir($stagingRoot);
                    foreach ($entries === false ? [] : $entries as $entry) {
                        if (str_starts_with($entry, 'anime-db-plugin-update-backup-')) {
                            $this->removeDirectory($stagingRoot.'/'.$entry);
                        }
                    }

                    throw new PluginCacheWarmupException('boom');
                }

                private function removeDirectory(string $dir): void
                {
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
            },
            $this->createStub(WsPublisher::class),
            logger: $logger,
        );

        $this->expectException(PluginCacheWarmupException::class);

        try {
            $installer->update($zipPath);
        } finally {
            // With the backup unrecoverable, the target directory is left empty (its old content
            // was already removed to make room for the restore) — reconcile() correctly drops the
            // plugin from the index rather than leaving it pointing at a directory with no
            // manifest.json, instead of the index staying stuck on a version that no longer works.
            $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
        }
    }

    /**
     * The native supervisor only learns an updated plugin needs to be made live via this event
     * (issue #224, same mechanism as {@see testInstallPublishesWorkersReloadEventAfterSuccessfulCacheWarmup()}).
     */
    #[Group('runtime-parity')]
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

    #[Group('runtime-parity')]
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

    /**
     * Issue #647 acceptance: installing a `translation`-type plugin that ships a
     * `translations/native/<locale>.json` produces the flattened overlay file for that locale —
     * exercised through the real install() path (ZipPluginInstaller::doInstall() calling
     * InstalledPluginsRegistry::reconcile() calling NativeTranslationsOverlayWriter::write()), not
     * by calling the writer directly.
     */
    #[Group('runtime-parity')]
    public function testInstallingTranslationPluginBuildsNativeTranslationsOverlay(): void
    {
        [$installer, , , $overlayDir] = $this->installerWithOverlayWriter();

        $zipPath = $this->createZip([
            'manifest.json' => $this->translationManifestJson('lang-kazakh', ['kk']),
            'translations/native/kk.json' => (string) json_encode(['tray.quit' => 'Шығу']),
        ]);

        $installer->install($zipPath);

        $this->assertSame(['tray.quit' => 'Шығу'], $this->readOverlay($overlayDir, 'kk'));
    }

    /**
     * Issue #647 acceptance: a broken `translations/native/` directory belonging to the plugin
     * currently being installed fails the install outright — see
     * ZipPluginInstaller::assertNativeTranslationsAreReadable(), called before anything is moved
     * into place, same "fail before committing" shape as the PHP syntax check just below it.
     */
    public function testInstallFailsWhenItsOwnNativeTranslationsCatalogIsInvalidJson(): void
    {
        [$installer, , $pluginsDir] = $this->installerWithOverlayWriter();

        $zipPath = $this->createZip([
            'manifest.json' => $this->translationManifestJson('lang-kazakh', ['kk']),
            'translations/native/kk.json' => '{not valid json',
        ]);

        $this->expectException(PluginInstallException::class);

        try {
            $installer->install($zipPath);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($pluginsDir), self::PLUGINS_DIR_HOUSEKEEPING_ENTRIES)));
        }
    }

    /**
     * Issue #647 acceptance: an unrelated, already-installed translation plugin's broken
     * `translations/native/` directory does not fail an install of a totally different plugin —
     * NativeTranslationsOverlayWriter treats a bystander's broken directory as "skip and log", see
     * its own class docblock, unlike the plugin currently being installed (covered above).
     */
    #[Group('runtime-parity')]
    public function testInstallSucceedsDespiteABystanderTranslationPluginsBrokenNativeCatalog(): void
    {
        [$installer, $registry, $pluginsDir] = $this->installerWithOverlayWriter();

        mkdir($pluginsDir.'/lang-broken/translations/native', recursive: true);
        file_put_contents($pluginsDir.'/lang-broken/manifest.json', $this->translationManifestJson('lang-broken', ['kk']));
        file_put_contents($pluginsDir.'/lang-broken/translations/native/kk.json', '{not valid json');
        $registry->reconcile();

        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
        ]);

        $pluginId = $installer->install($zipPath);

        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    /**
     * @return array{0: ZipPluginInstaller, 1: InstalledPluginsRegistry, 2: string, 3: string}
     *                                                                                         installer, its registry, the plugins dir, the overlay dir
     */
    private function installerWithOverlayWriter(): array
    {
        $rootDir = sys_get_temp_dir().'/anime-zip-installer-overlay-test-'.uniqid();
        $pluginsDir = $rootDir.'/plugins';
        $referenceDir = $rootDir.'/native-translations';
        $overlayDir = $rootDir.'/overlay';
        mkdir($pluginsDir, recursive: true);
        mkdir($referenceDir, recursive: true);
        file_put_contents($referenceDir.'/en.json', (string) json_encode(['tray.quit' => 'Quit']));

        $this->overlayTestRootDirs[] = $rootDir;

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
        $writer = new NativeTranslationsOverlayWriter($referenceDir, $overlayDir, $registry, new NullLogger());
        $registryWithWriter = new InstalledPluginsRegistry(
            $pluginsDir,
            new PluginsConfigStore($pluginsDir.'/plugins.json'),
            new NullLogger(),
            overlayWriter: $writer,
        );

        $installer = new ZipPluginInstaller(
            $pluginsDir,
            self::CORE_VERSION,
            $registryWithWriter,
            new PluginCacheWarmer($pluginsDir, \dirname(__DIR__, 4), new NullLogger()),
            $this->createStub(WsPublisher::class),
            logger: new NullLogger(),
        );

        return [$installer, $registryWithWriter, $pluginsDir, $overlayDir];
    }

    /**
     * @param list<string> $locales
     */
    private function translationManifestJson(string $pluginId, array $locales): string
    {
        return (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => $locales,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function readOverlay(string $overlayDir, string $locale): ?array
    {
        $path = $overlayDir.'/'.$locale.'.json';
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function installer(
        ?WsPublisher $wsPublisher = null,
        ?string $pluginContractsVersion = null,
        ?LoggerInterface $logger = null,
        ?string $coreVersion = self::CORE_VERSION,
    ): ZipPluginInstaller {
        return new ZipPluginInstaller(
            $this->pluginsDir,
            $coreVersion,
            $this->registry,
            $this->cacheWarmer(),
            $wsPublisher ?? $this->createStub(WsPublisher::class),
            $pluginContractsVersion,
            logger: $logger ?? new NullLogger(),
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

    private function validManifestJson(
        string $pluginId,
        string $version = '1.0.0',
        string $requireCore = '>=2.0.0',
        ?string $requirePluginContracts = null,
    ): string {
        $require = ['core' => $requireCore, 'php' => '>=8.2'];
        if ($requirePluginContracts !== null) {
            $require['plugin-contracts'] = $requirePluginContracts;
        }

        return (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => $require,
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
