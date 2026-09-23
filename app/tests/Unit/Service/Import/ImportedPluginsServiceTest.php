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

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\ImportedPluginsService;
use App\Service\Import\ImportedPluginStatus;
use App\Service\Market\PluginRegistryCache;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ImportedPluginsServiceTest extends TestCase
{
    private string $pluginsDir;
    private string $importAppliedPath;
    private string $registryCachePath;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/animedb-imported-plugins-test-plugins-'.uniqid();
        $this->importAppliedPath = sys_get_temp_dir().'/animedb-imported-plugins-test-applied-'.uniqid().'.json';
        $this->registryCachePath = sys_get_temp_dir().'/animedb-imported-plugins-test-cache-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
        @unlink($this->importAppliedPath);
        @unlink($this->registryCachePath);
    }

    public function testListIsEmptyWhenTheFileIsMissing(): void
    {
        $this->assertSame([], $this->createService()->list());
    }

    public function testListIsEmptyWhenTheFileIsNotValidJson(): void
    {
        file_put_contents($this->importAppliedPath, 'not json at all {{{');

        $this->assertSame([], $this->createService()->list());
    }

    public function testListIsEmptyWhenThePluginsFieldIsNotAList(): void
    {
        $this->writeManifest(['plugins' => ['not' => 'a list']]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testListIsEmptyWhenThePluginsFieldIsMissingEntirely(): void
    {
        $this->writeManifest(['formatVersion' => 1]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testSkipsAnEntryThatIsNotAnObject(): void
    {
        $this->writeManifest(['plugins' => ['just-a-string', ['id' => 'animedb-shikimori']]]);

        $result = $this->createService()->list();

        $this->assertCount(1, $result);
        $this->assertSame('animedb-shikimori', $result[0]->id);
    }

    public function testSkipsAnEntryWhoseIdDoesNotMatchThePluginIdFormat(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'Not A Valid Id!']]]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testSkipsAnEntryWhoseIdIsNotAString(): void
    {
        $this->writeManifest(['plugins' => [['id' => 12345]]]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testSkipsAnEntryWhoseNameIsNotAString(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori', 'name' => ['not', 'a', 'string']]]]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testSkipsAnEntryWhoseVersionIsNotAString(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori', 'version' => 123]]]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testSkipsAnEntryWithAStringFieldLongerThan200Characters(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori', 'name' => str_repeat('x', 201)]]]);

        $this->assertSame([], $this->createService()->list());
    }

    public function testDisplayNameFallsBackToIdWhenNameIsAbsent(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori']]]);

        $result = $this->createService()->list();

        $this->assertCount(1, $result);
        $this->assertSame('animedb-shikimori', $result[0]->displayName);
    }

    public function testDisplayNameUsesNameWhenPresent(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori', 'name' => 'Shikimori']]]);

        $result = $this->createService()->list();

        $this->assertSame('Shikimori', $result[0]->displayName);
    }

    public function testAnInstalledPluginIsMarkedInstalled(): void
    {
        $this->installPlugin('animedb-shikimori');
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori']]]);

        $result = $this->createService()->list();

        $this->assertSame(ImportedPluginStatus::Installed, $result[0]->status);
    }

    public function testANotInstalledPluginIsMarkedCheckMarketWhenNoRegistryCacheExists(): void
    {
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori']]]);

        $result = $this->createService()->list();

        $this->assertSame(ImportedPluginStatus::CheckMarket, $result[0]->status);
    }

    public function testANotInstalledPluginListedInTheCachedRegistryIsMarkedAvailableInMarket(): void
    {
        $this->storeRegistryCache(['animedb-shikimori']);
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori']]]);

        $result = $this->createService()->list();

        $this->assertSame(ImportedPluginStatus::AvailableInMarket, $result[0]->status);
    }

    public function testANotInstalledPluginMissingFromTheCachedRegistryIsMarkedManualInstall(): void
    {
        $this->storeRegistryCache(['some-other-plugin']);
        $this->writeManifest(['plugins' => [['id' => 'animedb-shikimori']]]);

        $result = $this->createService()->list();

        $this->assertSame(ImportedPluginStatus::ManualInstall, $result[0]->status);
    }

    /**
     * Regression guard for issue #726 acceptance criterion 6: a manifest naming far more plugins
     * than any real installation could ever have must not be trusted wholesale, and resolving it
     * must not re-read the installed-plugin index once per entry.
     * {@see InstalledPluginsRegistry::readIndex()}'s own docblock explains why calling has()/get()
     * in a loop is a footgun — it never caches and re-parses the whole index file on every call.
     */
    public function testListTruncatesToTheFirst100EntriesAndReadsTheInstalledRegistryExactlyOnce(): void
    {
        $counterPath = sys_get_temp_dir().'/animedb-imported-plugins-test-counter-'.uniqid();
        $this->writeCountingEmptyIndex($counterPath);

        $plugins = [];
        for ($i = 0; $i < 10_000; ++$i) {
            $plugins[] = ['id' => \sprintf('plugin-%06d', $i)];
        }
        $this->writeManifest(['plugins' => $plugins]);

        try {
            $result = $this->createService()->list();

            $this->assertCount(100, $result);
            $this->assertSame('plugin-000000', $result[0]->id);
            $this->assertSame('plugin-000099', $result[99]->id);
            $this->assertSame('1', file_get_contents($counterPath));
        } finally {
            @unlink($counterPath);
        }
    }

    public function testDismissRemovesTheFile(): void
    {
        $this->writeManifest(['plugins' => []]);

        $this->createService()->dismiss();

        $this->assertFileDoesNotExist($this->importAppliedPath);
    }

    public function testDismissDoesNothingWhenTheFileIsAlreadyMissing(): void
    {
        $this->createService()->dismiss();

        $this->assertFileDoesNotExist($this->importAppliedPath);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->importAppliedPath, (string) json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    private function installPlugin(string $pluginId, string $version = '1.0.0'): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $this->registry()->reconcile();
    }

    /**
     * @param list<string> $pluginIds
     */
    private function storeRegistryCache(array $pluginIds): void
    {
        $cache = new PluginRegistryCache($this->registryCachePath, new NullLogger());
        $cache->store((string) json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => array_map(static fn (string $id): array => [
                'id' => $id,
                'manifest' => [
                    'id' => $id,
                    'name' => ucfirst($id),
                    'version' => '1.0.0',
                    'type' => 'integration',
                    'features' => ['filler' => true],
                    'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
                ],
                'versions' => [
                    ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
                ],
            ], $pluginIds),
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Hand-writes an `installed-plugins.php` index that reports no installed plugins but
     * increments a counter file every time {@see InstalledPluginsRegistry::readIndex()} `require`s
     * it — the only way to observe how many times {@see InstalledPluginsRegistry::all()} actually
     * ran, since the class is final and cannot be mocked.
     */
    private function writeCountingEmptyIndex(string $counterPath): void
    {
        mkdir($this->pluginsDir, recursive: true);
        $counterPathLiteral = var_export($counterPath, true);
        file_put_contents($this->pluginsDir.'/installed-plugins.php', <<<PHP
            <?php

            \$count = is_file({$counterPathLiteral}) ? (int) file_get_contents({$counterPathLiteral}) : 0;
            file_put_contents({$counterPathLiteral}, (string) (\$count + 1));

            return [];

            PHP);
    }

    private function createService(): ImportedPluginsService
    {
        return new ImportedPluginsService(
            new PluginRegistryCache($this->registryCachePath, new NullLogger()),
            $this->registry(),
            $this->importAppliedPath,
        );
    }

    private function registry(): InstalledPluginsRegistry
    {
        return new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
