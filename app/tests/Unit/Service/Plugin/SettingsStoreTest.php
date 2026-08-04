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
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsStore;
use PHPUnit\Framework\TestCase;

final class SettingsStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-settings-store-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReadDefaultsToEmptyArrayWhenNothingWasStoredYet(): void
    {
        $store = new SettingsStore(new PluginId('animedb-shikimori'), new PluginsConfigStore($this->path));

        $this->assertSame([], $store->read());
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = new SettingsStore(new PluginId('animedb-shikimori'), new PluginsConfigStore($this->path));

        $store->write(['refreshToken' => 'abc']);

        $this->assertSame(['refreshToken' => 'abc'], $store->read());
    }

    public function testWriteOverridesRatherThanMergesWithThePreviousPayload(): void
    {
        $store = new SettingsStore(new PluginId('animedb-shikimori'), new PluginsConfigStore($this->path));

        $store->write(['refreshToken' => 'abc', 'endpoint' => 'https://example.test']);
        $store->write(['endpoint' => 'https://example.test']);

        $this->assertSame(['endpoint' => 'https://example.test'], $store->read());
    }

    public function testDifferentPluginsDoNotShareSettings(): void
    {
        $configStore = new PluginsConfigStore($this->path);
        $shikimori = new SettingsStore(new PluginId('animedb-shikimori'), $configStore);
        $anilist = new SettingsStore(new PluginId('animedb-anilist'), $configStore);

        $shikimori->write(['refreshToken' => 'shiki-token']);
        $anilist->write(['refreshToken' => 'anilist-token']);

        $this->assertSame(['refreshToken' => 'shiki-token'], $shikimori->read());
        $this->assertSame(['refreshToken' => 'anilist-token'], $anilist->read());
    }

    /**
     * A plugin writing a `features` key of its own through the settings store must never
     * shadow the host's own `features` flags (widget/filler/sync toggles) stored in the very
     * same plugins.json entry (issue #316).
     */
    public function testWriteDoesNotCollideWithHostEnabledAndFeaturesFlags(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $configStore = new PluginsConfigStore($this->path);
        $configStore->updatePluginSettings($pluginId, static fn (): array => [
            'enabled' => true,
            'features' => ['filler' => false],
        ]);

        $store = new SettingsStore($pluginId, $configStore);
        $store->write(['features' => ['syncedAt' => '2026-08-04']]);

        $this->assertSame(['features' => ['syncedAt' => '2026-08-04']], $store->read());
        $this->assertSame(
            ['enabled' => true, 'features' => ['filler' => false], 'settings' => ['features' => ['syncedAt' => '2026-08-04']]],
            $configStore->getPluginSettings($pluginId),
        );
    }
}
