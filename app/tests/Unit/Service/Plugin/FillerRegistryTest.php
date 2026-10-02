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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\FillerAvailabilityState;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class FillerRegistryTest extends TestCase
{
    private string $path;
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'.json';
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugins-dir-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeDirectory($this->pluginsDir);
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

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    /** @param string[] $fillableFields */
    private function createFiller(array $fillableFields): FillerInterface
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn($fillableFields);

        return $filler;
    }

    public function testFindByFieldReturnsOnlyFillersSupportingTheField(): void
    {
        $shikimori = $this->createFiller(['title', 'description']);
        $anilist = $this->createFiller(['title']);
        $mal = $this->createFiller(['genres']);

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist, 'animedb-mal' => $mal],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([$shikimori, $anilist], $registry->findByField('title'));
    }

    public function testFindByFieldReturnsEmptyListWhenNoFillerSupportsTheField(): void
    {
        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['description'])],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([], $registry->findByField('title'));
    }

    public function testFindByFieldTreatsPluginWithoutRecordedSettingsAsActive(): void
    {
        $shikimori = $this->createFiller(['title']);

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $shikimori],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([$shikimori], $registry->findByField('title'));
    }

    public function testFindByFieldExcludesPluginDisabledViaFeaturesFiller(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['title'])],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([], $registry->findByField('title'));
    }

    public function testFindByFieldKeepsPluginWithOtherFeatureFlagsDisabled(): void
    {
        $shikimori = $this->createFiller(['title']);
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['relatedWidget' => false]],
        ]));

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $shikimori],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([$shikimori], $registry->findByField('title'));
    }

    public function testFindWithIdByFieldKeysResultByPluginId(): void
    {
        $shikimori = $this->createFiller(['title', 'description']);
        $anilist = $this->createFiller(['title']);
        $mal = $this->createFiller(['genres']);

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist, 'animedb-mal' => $mal],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(
            ['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist],
            $registry->findWithIdByField('title'),
        );
    }

    public function testFindWithIdByFieldExcludesPluginDisabledViaFeaturesFiller(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['title'])],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([], $registry->findWithIdByField('title'));
    }

    public function testFindByPluginIdReturnsTheMatchingFiller(): void
    {
        $shikimori = $this->createFiller(['title']);
        $anilist = $this->createFiller(['title']);

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($shikimori, $registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testFindByPluginIdReturnsNullWhenNoFillerIsRegisteredUnderThatId(): void
    {
        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['title'])],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->findByPluginId(new PluginId('animedb-anilist')));
    }

    public function testFindByPluginIdReturnsNullWhenTheMatchingPluginIsDisabled(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['title'])],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testFindAllActiveExcludesPluginDisabledViaFeaturesFiller(): void
    {
        $shikimori = $this->createFiller(['title']);
        $anilist = $this->createFiller(['title']);
        file_put_contents($this->path, json_encode([
            'animedb-anilist' => ['features' => ['filler' => false]],
        ]));

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(['animedb-shikimori' => $shikimori], $registry->findAllActive());
    }

    public function testFillerAvailabilityReturnsNotInstalledWhenNoFillerIsRegistered(): void
    {
        $registry = new FillerRegistry([], new PluginsConfigStore($this->path));
        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([]));

        $availability = $registry->fillerAvailability($installedPlugins, $settingsPages);

        $this->assertSame(FillerAvailabilityState::NotInstalled, $availability->state);
        $this->assertNull($availability->pluginId);
    }

    public function testFillerAvailabilityReturnsDisabledNoSettingsPageWhenTheWholePluginIsDisabled(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['enabled' => false],
        ]));

        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $installedPlugins->reconcile();

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['title'])],
            new PluginsConfigStore($this->path),
        );
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([]));

        $availability = $registry->fillerAvailability($installedPlugins, $settingsPages);

        $this->assertSame(FillerAvailabilityState::DisabledNoSettingsPage, $availability->state);
    }

    public function testFillerAvailabilityReturnsDisabledWithSettingsPageWhenOnlyTheFillerFeatureIsOff(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $installedPlugins->reconcile();

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = new FillerRegistry(
            ['animedb-shikimori' => $this->createFiller(['title'])],
            new PluginsConfigStore($this->path),
        );

        $settingsPage = $this->createStub(SettingsPageInterface::class);
        $settingsPages = new SettingsPageRegistry($installedPlugins, new ServiceLocator([
            'animedb-shikimori' => static fn (): SettingsPageInterface => $settingsPage,
        ]));

        $availability = $registry->fillerAvailability($installedPlugins, $settingsPages);

        $this->assertSame(FillerAvailabilityState::DisabledWithSettingsPage, $availability->state);
        $this->assertSame('animedb-shikimori', (string) $availability->pluginId);
    }
}
