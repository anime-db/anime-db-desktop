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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use App\Service\Plugin\Filler\FillableFieldsPresenter;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FillableFieldsPresenterTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-fillable-fields-test-'.uniqid();
        mkdir($this->pluginsDir.'/animedb-shikimori', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    protected function tearDown(): void
    {
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

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /** @param string[] $fillableFields */
    private function createFiller(array $fillableFields): FillerInterface
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn($fillableFields);

        return $filler;
    }

    public function testBuildListsTheInstalledPluginSNameForEachFieldItSupports(): void
    {
        $configPath = $this->pluginsDir.'/plugins.json';
        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($configPath), new NullLogger());
        $installedPlugins->reconcile();

        $presenter = new FillableFieldsPresenter(
            new FillerRegistry(['animedb-shikimori' => $this->createFiller(['genres', 'studios', 'cover', 'images'])], new PluginsConfigStore($configPath)),
            $installedPlugins,
        );

        $result = $presenter->build();

        $this->assertSame([['id' => 'animedb-shikimori', 'name' => 'Shikimori']], $result['genres']);
        $this->assertSame([['id' => 'animedb-shikimori', 'name' => 'Shikimori']], $result['studios']);
        $this->assertSame([['id' => 'animedb-shikimori', 'name' => 'Shikimori']], $result['cover']);
        $this->assertSame([['id' => 'animedb-shikimori', 'name' => 'Shikimori']], $result['images']);
        $this->assertSame([], $result['countries']);
    }

    public function testBuildFallsBackToThePluginIdWhenItIsNotInTheInstalledPluginsIndex(): void
    {
        $configPath = $this->pluginsDir.'/plugins.json';
        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($configPath), new NullLogger());
        // Deliberately not reconciled: the filler is registered in the container (as a plugin
        // service tag would be in production) but the on-disk installed-plugins.php index -
        // reconcile()'s output - is missing or stale.

        $presenter = new FillableFieldsPresenter(
            new FillerRegistry(['animedb-shikimori' => $this->createFiller(['genres'])], new PluginsConfigStore($configPath)),
            $installedPlugins,
        );

        $this->assertSame([['id' => 'animedb-shikimori', 'name' => 'animedb-shikimori']], $presenter->build()['genres']);
    }

    public function testBuildReturnsAnEmptyListForAFieldNoActiveFillerSupports(): void
    {
        $installedPlugins = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore(''), new NullLogger());

        $presenter = new FillableFieldsPresenter(new FillerRegistry([], new PluginsConfigStore('')), $installedPlugins);

        foreach ($presenter->build() as $plugins) {
            $this->assertSame([], $plugins);
        }
    }
}
