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

use App\Service\AppConfigStore;
use App\Service\Market\PluginRegistryHighWaterMarkStore;
use PHPUnit\Framework\TestCase;

final class PluginRegistryHighWaterMarkStoreTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-market-high-water-mark-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReturnsNullWhenNothingWasEverPersisted(): void
    {
        $store = new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath));

        $this->assertNull($store->getSequence());
    }

    public function testRaisePersistsTheSequence(): void
    {
        $store = new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath));

        $store->raise(5);

        $this->assertSame(5, $store->getSequence());
        // A fresh instance reading the same file must see the same persisted value.
        $this->assertSame(5, (new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath)))->getSequence());
    }

    public function testRaiseNeverLowersAnAlreadyPersistedSequence(): void
    {
        $store = new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath));

        $store->raise(5);
        $store->raise(3);

        $this->assertSame(5, $store->getSequence());
    }

    public function testRaiseAdvancesAnAlreadyPersistedSequence(): void
    {
        $store = new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath));

        $store->raise(5);
        $store->raise(9);

        $this->assertSame(9, $store->getSequence());
    }

    public function testSharesTheConfigFileWithOtherAppSettingsWithoutClobberingThem(): void
    {
        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static function (array $config): array {
            $config['locale'] = 'ru';

            return $config;
        });

        (new PluginRegistryHighWaterMarkStore($configStore))->raise(5);

        $config = $configStore->read();
        $this->assertSame('ru', $config['locale']);
        $this->assertSame(5, $config['marketRegistryHighWaterMarkSequence']);
    }
}
