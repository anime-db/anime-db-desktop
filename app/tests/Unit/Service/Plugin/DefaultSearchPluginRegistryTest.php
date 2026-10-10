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

use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\DefaultSearchPluginRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class DefaultSearchPluginRegistryTest extends TestCase
{
    private string $pluginsConfigPath;
    private string $appConfigPath;

    protected function setUp(): void
    {
        $this->pluginsConfigPath = sys_get_temp_dir().'/anime-default-search-plugins-'.uniqid().'.json';
        $this->appConfigPath = sys_get_temp_dir().'/anime-default-search-config-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginsConfigPath, $this->pluginsConfigPath.'.tmp', $this->pluginsConfigPath.'.lock', $this->appConfigPath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createSearch(): SearchByPluginInterface
    {
        return $this->createStub(SearchByPluginInterface::class);
    }

    /** @param iterable<string, SearchByPluginInterface> $plugins */
    private function registry(iterable $plugins): DefaultSearchPluginRegistry
    {
        return new DefaultSearchPluginRegistry(
            $plugins,
            new PluginsConfigStore($this->pluginsConfigPath),
            new AppSettingsProvider(new AppConfigStore($this->appConfigPath)),
        );
    }

    /** @return array<string, mixed> */
    private function storedSettings(): array
    {
        return json_decode((string) file_get_contents($this->appConfigPath), true);
    }

    public function testSelectedReturnsNullWhenNoSearchPluginIsInstalled(): void
    {
        $this->assertNull($this->registry([])->selected());
    }

    public function testSelectedWithoutStoredChoiceReturnsNullAndDoesNotTouchSettings(): void
    {
        $registry = $this->registry([
            'animedb-anidb' => $this->createSearch(),
            'animedb-anilist' => $this->createSearch(),
        ]);

        $this->assertNull($registry->selected());
        $this->assertNull($registry->unavailableSelected());
        $this->assertFileDoesNotExist($this->appConfigPath);

        file_put_contents($this->appConfigPath, '{"other":1}');
        $this->assertNull($registry->selected());
        $this->assertSame('{"other":1}', file_get_contents($this->appConfigPath));
    }

    public function testSelectedReturnsTheStoredPluginWhenAvailable(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-anilist']));

        $registry = $this->registry([
            'animedb-shikimori' => $this->createSearch(),
            'animedb-anilist' => $this->createSearch(),
        ]);

        $this->assertSame('animedb-anilist', (string) $registry->selected());
        $this->assertNull($registry->unavailableSelected());
    }

    public function testUnavailableStoredChoiceIsExposedAndSettingsStayUntouched(): void
    {
        $stored = json_encode(['defaultSearchPluginId' => 'animedb-gone']);
        file_put_contents($this->appConfigPath, $stored);

        $registry = $this->registry(['animedb-anilist' => $this->createSearch()]);

        $this->assertNull($registry->selected());
        $this->assertSame('animedb-gone', (string) $registry->unavailableSelected());
        $this->assertSame($stored, file_get_contents($this->appConfigPath));
    }

    public function testStoredChoiceWithDisabledFillerFeatureIsUnavailable(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));
        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $registry = $this->registry([
            'animedb-shikimori' => $this->createSearch(),
            'animedb-anilist' => $this->createSearch(),
        ]);

        $this->assertNull($registry->selected());
        $this->assertSame('animedb-shikimori', (string) $registry->unavailableSelected());
        $this->assertSame(['animedb-anilist'], array_map('strval', $registry->available()));
    }

    public function testSelectPersistsAnAvailablePlugin(): void
    {
        $registry = $this->registry(['animedb-anilist' => $this->createSearch()]);

        $this->assertTrue($registry->select(new PluginId('animedb-anilist')));
        $this->assertSame('animedb-anilist', (string) $registry->selected());
    }

    public function testSelectRejectsAnUnavailablePluginWithoutWriting(): void
    {
        $registry = $this->registry(['animedb-anilist' => $this->createSearch()]);

        $this->assertFalse($registry->select(new PluginId('animedb-unknown')));
        $this->assertFileDoesNotExist($this->appConfigPath);
    }

    public function testSelectAcceptsResubmittingTheStoredUnavailableChoice(): void
    {
        $stored = json_encode(['defaultSearchPluginId' => 'animedb-gone']);
        file_put_contents($this->appConfigPath, $stored);
        $registry = $this->registry(['animedb-anilist' => $this->createSearch()]);

        $this->assertTrue($registry->select(new PluginId('animedb-gone')));
        $this->assertSame($stored, file_get_contents($this->appConfigPath));
    }

    public function testSelectNullClearsTheChoice(): void
    {
        file_put_contents($this->appConfigPath, json_encode(['defaultSearchPluginId' => 'animedb-anilist']));
        $registry = $this->registry(['animedb-anilist' => $this->createSearch()]);

        $this->assertTrue($registry->select(null));
        $this->assertNull($this->storedSettings()['defaultSearchPluginId']);
        $this->assertNull($registry->selected());
    }
}
