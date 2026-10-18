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

namespace App\Tests\Unit\Service\Market;

use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\IncompatibleCoreSnapshotException;
use App\Service\Market\Exception\IncompatiblePluginContractsSnapshotException;
use App\Service\Market\Exception\MarketSnapshotUnavailableException;
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\PluginNotInSnapshotException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use App\Service\Market\MarketAssetDownloader;
use App\Service\Market\MarketPluginInstaller;
use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotPlugin;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginNotInstalledException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketPluginInstallerTest extends TestCase
{
    private const string CORE_VERSION = '2.5.0';
    private const string PLUGIN_ID = 'animedb-shikimori';

    private string $rootDir;
    private string $pluginsDir;
    private MarketSnapshotCache $snapshotCache;
    private InstalledPluginsRegistry $installedPlugins;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-market-installer-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->snapshotCache = new MarketSnapshotCache($this->rootDir.'/snapshot.json');
        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testInstallReturnsPluginIdAndRemovesTemporaryZip(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $tmpFiles = $this->tempAssetFiles();

        $id = $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID));

        self::assertSame(self::PLUGIN_ID, (string) $id);
        self::assertNotNull($this->installedPlugins->get($id));
        self::assertSame($tmpFiles, $this->tempAssetFiles());
    }

    public function testUpdateReturnsPluginId(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID));

        $id = $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID), update: true);

        self::assertSame(self::PLUGIN_ID, (string) $id);
    }

    public function testMissingSnapshotIsReportedDistinctly(): void
    {
        $this->expectException(MarketSnapshotUnavailableException::class);

        $this->installer('')->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testSnapshotBuiltForAnotherCoreIsReportedAsUnavailable(): void
    {
        $this->snapshotCache->store(new MarketSnapshot('1.0.0', 1, ['https://mirror.example/<id>/<version>/<file>'], [$this->plugin('abc')]));

        $this->expectException(MarketSnapshotUnavailableException::class);

        $this->installer('')->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testPluginMissingFromSnapshotIsReportedDistinctly(): void
    {
        $this->storeSnapshot($this->plugin('abc'));

        $this->expectException(PluginNotInSnapshotException::class);

        $this->installer('')->installOrUpdate(new PluginId('animedb-other'));
    }

    public function testNoVersionCompatibleWithPluginContractsIsReportedDistinctly(): void
    {
        $this->storeSnapshot($this->plugin(null, resolvedVersion: null, incompatiblePluginContracts: true));

        $this->expectException(IncompatiblePluginContractsSnapshotException::class);

        $this->installer('')->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testNoVersionCompatibleWithCoreCarriesRequiredCore(): void
    {
        $this->storeSnapshot($this->plugin(null, resolvedVersion: null, latestVersionCore: '>=3.0.0'));

        try {
            $this->installer('')->installOrUpdate(new PluginId(self::PLUGIN_ID));
            self::fail('Expected IncompatibleCoreSnapshotException.');
        } catch (IncompatibleCoreSnapshotException $exception) {
            self::assertSame('>=3.0.0', $exception->requiredCore);
        }
    }

    public function testMissingChecksumIsReportedAsUnknownPluginVersion(): void
    {
        $this->storeSnapshot($this->plugin(null));

        $this->expectException(UnknownPluginVersionException::class);

        $this->installer('')->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testChecksumMismatchIsReportedAsDownloadFailure(): void
    {
        $this->storeSnapshot($this->plugin(hash('sha256', 'something else')));

        $this->expectException(PluginAssetDownloadException::class);

        $this->installer($this->pluginZipBytes())->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testInstallerCoreIncompatibilityKeepsItsType(): void
    {
        $zip = $this->pluginZipBytes(requireCore: '>=9.0.0');
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $tmpFiles = $this->tempAssetFiles();

        try {
            $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID));
            self::fail('Expected IncompatiblePluginCoreVersionException.');
        } catch (IncompatiblePluginCoreVersionException $exception) {
            self::assertSame('>=9.0.0', $exception->requiredCore);
        }

        self::assertSame($tmpFiles, $this->tempAssetFiles(), 'The temporary zip is removed on failure too.');
    }

    public function testAlreadyInstalledKeepsItsType(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID));

        $this->expectException(PluginAlreadyInstalledException::class);

        $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testUpdateOfNotInstalledPluginKeepsItsType(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));

        $this->expectException(PluginNotInstalledException::class);

        $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID), update: true);
    }

    public function testWorkersReloadIsPublishedByDefault(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ZipPluginInstaller::WORKERS_RELOAD_EVENT, ['pluginId' => self::PLUGIN_ID]);

        $this->installer($zip, $wsPublisher)->installOrUpdate(new PluginId(self::PLUGIN_ID));
    }

    public function testWorkersReloadIsNotPublishedWhenDisabled(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->never())->method('publish');

        $id = $this->installer($zip, $wsPublisher)->installOrUpdate(new PluginId(self::PLUGIN_ID), publishWorkersReload: false);

        self::assertNotNull($this->installedPlugins->get($id));
    }

    public function testWorkersReloadIsNotPublishedOnUpdateWhenDisabled(): void
    {
        $zip = $this->pluginZipBytes();
        $this->storeSnapshot($this->plugin(hash('sha256', $zip)));
        $this->installer($zip)->installOrUpdate(new PluginId(self::PLUGIN_ID), publishWorkersReload: false);
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->never())->method('publish');

        $this->installer($zip, $wsPublisher)->installOrUpdate(new PluginId(self::PLUGIN_ID), update: true, publishWorkersReload: false);
    }

    private function installer(string $zipBytes, ?WsPublisher $wsPublisher = null): MarketPluginInstaller
    {
        return new MarketPluginInstaller(
            $this->snapshotCache,
            new MarketAssetDownloader(new MockHttpClient(fn (): MockResponse => new MockResponse($zipBytes), null)),
            new ZipPluginInstaller(
                $this->pluginsDir,
                self::CORE_VERSION,
                $this->installedPlugins,
                new PluginCacheWarmer($this->pluginsDir, \dirname(__DIR__, 4), new NullLogger()),
                $wsPublisher ?? $this->createStub(WsPublisher::class),
            ),
            self::CORE_VERSION,
        );
    }

    private function storeSnapshot(MarketSnapshotPlugin $plugin): void
    {
        $this->snapshotCache->store(new MarketSnapshot(self::CORE_VERSION, 1, ['https://mirror.example/<id>/<version>/<file>'], [$plugin]));
    }

    private function plugin(
        ?string $sha256,
        ?string $resolvedVersion = '1.2.0',
        string $latestVersionCore = '>=2.0.0',
        bool $incompatiblePluginContracts = false,
    ): MarketSnapshotPlugin {
        return new MarketSnapshotPlugin(
            self::PLUGIN_ID,
            $this->manifest('1.2.0', '>=2.0.0'),
            $resolvedVersion,
            $sha256,
            '1.2.0',
            $latestVersionCore,
            incompatiblePluginContracts: $incompatiblePluginContracts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $version, string $requireCore): array
    {
        return [
            'id' => self::PLUGIN_ID,
            'name' => 'Shikimori',
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => $requireCore, 'php' => '>=8.2'],
        ];
    }

    private function pluginZipBytes(string $requireCore = '>=2.0.0'): string
    {
        $zipPath = $this->rootDir.'/'.uniqid('plugin-zip-src-', true).'.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode($this->manifest('1.2.0', $requireCore)));
        $zip->close();

        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private function tempAssetFiles(): array
    {
        return glob(sys_get_temp_dir().'/anime-db-plugin-asset-*') ?: [];
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
}
