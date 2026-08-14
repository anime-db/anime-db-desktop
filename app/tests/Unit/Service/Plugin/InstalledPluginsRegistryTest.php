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

use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class InstalledPluginsRegistryTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-installed-plugins-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testAllReturnsEmptyListWhenPluginsDirectoryDoesNotExist(): void
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir.'/does-not-exist',
            $this->configStore(),
            new NullLogger(),
        );

        $this->assertSame([], $registry->all());
    }

    public function testReconcileOnEmptyDirectoryProducesEmptyRegistry(): void
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());

        $registry->reconcile();

        $this->assertSame([], $registry->all());
    }

    public function testReconcileEnumeratesMultipleValidPlugins(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->writeManifest('animedb-anilist');

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $this->assertSame(['animedb-anilist', 'animedb-shikimori'], $this->ids($registry->all()));
    }

    public function testAllExposesManifestAndInstallPath(): void
    {
        $this->writeManifest('animedb-shikimori', '1.2.3');

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $plugin = $registry->all()[0];

        $this->assertSame('animedb-shikimori', $plugin->manifest->id);
        $this->assertSame('1.2.3', $plugin->manifest->version);
        $this->assertSame(PluginType::Integration, $plugin->manifest->type);
        $this->assertSame($this->pluginsDir.'/animedb-shikimori', $plugin->installPath);
        $this->assertTrue($plugin->enabled);
    }

    public function testReconcileAcceptsLocalPluginType(): void
    {
        $dir = $this->pluginsDir.'/animedb-onboarding';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-onboarding',
            'name' => 'Onboarding',
            'version' => '1.0.0',
            'type' => 'local',
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $plugin = $registry->all()[0];

        $this->assertSame(PluginType::Local, $plugin->manifest->type);
    }

    public function testEnabledFiltersOutPluginsDisabledInPluginsConfigStore(): void
    {
        $this->writeManifest('animedb-shikimori');
        $this->writeManifest('animedb-anilist');

        $configPath = $this->pluginsDir.'/plugins.json';
        file_put_contents($configPath, json_encode(['animedb-anilist' => ['enabled' => false]]));

        $registry = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($configPath), new NullLogger());
        $registry->reconcile();

        $this->assertSame(['animedb-shikimori'], $this->ids($registry->enabled()));
        $this->assertSame(['animedb-anilist', 'animedb-shikimori'], $this->ids($registry->all()));
    }

    public function testReconcileSkipsDirectoryWithInvalidManifestJsonAndKeepsOthers(): void
    {
        $this->writeManifest('animedb-shikimori');

        $brokenDir = $this->pluginsDir.'/animedb-broken';
        mkdir($brokenDir, recursive: true);
        file_put_contents($brokenDir.'/manifest.json', '{not valid json');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('invalid manifest.json'),
            $this->callback(static fn (array $context): bool => $context['pluginDir'] === $brokenDir),
        );

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), $logger);
        $registry->reconcile();

        $this->assertSame(['animedb-shikimori'], $this->ids($registry->all()));
    }

    public function testReconcileSkipsDirectoryMissingManifestFile(): void
    {
        $this->writeManifest('animedb-shikimori');
        mkdir($this->pluginsDir.'/animedb-no-manifest', recursive: true);

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $this->assertSame(['animedb-shikimori'], $this->ids($registry->all()));
    }

    public function testReadIndexSkipsEntryWithInvalidPluginIdAndKeepsOthers(): void
    {
        $this->writeManifest('animedb-shikimori');

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        // Simulates a persistent index entry written by a pre-0.4.0 plugin-contracts version,
        // before the manifest parser validated the "id" format on reconcile.
        $this->appendBrokenIndexEntry('not_a_valid_id');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error')->with(
            $this->stringContains('invalid index entry'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'not_a_valid_id'),
        );

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), $logger);

        $this->assertSame(['animedb-shikimori'], $this->ids($registry->all()));
        $this->assertSame(['animedb-shikimori'], $this->ids($registry->enabled()));
    }

    public function testGetReturnsMatchingPlugin(): void
    {
        $this->writeManifest('animedb-shikimori');

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $plugin = $registry->get(new PluginId('animedb-shikimori'));

        $this->assertNotNull($plugin);
        $this->assertSame('animedb-shikimori', $plugin->manifest->id);
    }

    public function testGetReturnsNullForUnknownPlugin(): void
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $this->assertNull($registry->get(new PluginId('animedb-unknown')));
    }

    public function testHasReturnsTrueForInstalledPlugin(): void
    {
        $this->writeManifest('animedb-shikimori');

        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $this->assertTrue($registry->has(new PluginId('animedb-shikimori')));
    }

    public function testHasReturnsFalseForUnknownPlugin(): void
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $this->assertFalse($registry->has(new PluginId('animedb-unknown')));
    }

    public function testSafeModeReportsNoPluginsEvenWhenInstalled(): void
    {
        $this->writeManifest('animedb-shikimori');
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        $safeModeRegistry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            $this->configStore(),
            new NullLogger(),
            safeMode: true,
        );

        $this->assertSame([], $safeModeRegistry->all());
        $this->assertSame([], $safeModeRegistry->enabled());
        $this->assertNull($safeModeRegistry->get(new PluginId('animedb-shikimori')));
        $this->assertFalse($safeModeRegistry->has(new PluginId('animedb-shikimori')));
    }

    public function testSafeModeDoesNotReadTheIndexFile(): void
    {
        $this->writeManifest('animedb-shikimori');
        $registry = new InstalledPluginsRegistry($this->pluginsDir, $this->configStore(), new NullLogger());
        $registry->reconcile();

        // A corrupted index would normally surface as a logged "invalid index entry" error the
        // moment readIndex() parses it — safe mode must never reach that code path at all.
        file_put_contents($this->pluginsDir.'/installed-plugins.php', '<?php throw new RuntimeException("must not be read in safe mode");');

        $safeModeRegistry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            $this->configStore(),
            new NullLogger(),
            safeMode: true,
        );

        $this->assertSame([], $safeModeRegistry->all());
    }

    private function configStore(): PluginsConfigStore
    {
        return new PluginsConfigStore($this->pluginsDir.'/plugins.json');
    }

    /**
     * Directly rewrites `installed-plugins.php` to inject an entry with an invalid "id" next to
     * whatever is already there, bypassing {@see InstalledPluginsRegistry::reconcile()} (which
     * would reject it) to simulate a persisted index written by an older, less strict parser.
     */
    private function appendBrokenIndexEntry(string $brokenId): void
    {
        $indexPath = $this->pluginsDir.'/installed-plugins.php';
        $entries = require $indexPath;

        $entries[$brokenId] = [
            'installPath' => $this->pluginsDir.'/animedb-broken',
            'manifest' => [
                'id' => $brokenId,
                'name' => 'Broken',
                'version' => '1.0.0',
                'type' => 'integration',
                'require' => ['core' => '>=2.0.0', 'php' => '>=8.2', 'pluginContracts' => '>=0.4.0'],
                'description' => null,
                'author' => null,
                'features' => [],
                'locales' => [],
                'updateUrl' => null,
            ],
        ];

        file_put_contents($indexPath, "<?php\n\nreturn ".var_export($entries, true).";\n");
    }

    private function writeManifest(string $pluginId, string $version = '1.0.0'): void
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
    }

    /**
     * @param list<InstalledPlugin> $plugins
     *
     * @return list<string>
     */
    private function ids(array $plugins): array
    {
        $ids = array_map(static fn (InstalledPlugin $plugin): string => (string) $plugin->id, $plugins);
        sort($ids);

        return $ids;
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
