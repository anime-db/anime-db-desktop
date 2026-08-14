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

use App\Service\Market\PluginRegistryCache;
use PHPUnit\Framework\TestCase;

final class PluginRegistryCacheTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-market-registry-cache-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReturnsNullWhenNoCacheFileExists(): void
    {
        $cache = new PluginRegistryCache($this->path);

        $this->assertNull($cache->getCachedRegistry());
        $this->assertNull($cache->getLastSequence());
    }

    public function testStoresAndRetrievesTheRegistry(): void
    {
        $cache = new PluginRegistryCache($this->path);

        $cache->store($this->registryJson(sequence: 7));

        $cached = $cache->getCachedRegistry();
        $this->assertNotNull($cached);
        $this->assertSame(7, $cached->sequence);
        $this->assertSame(7, $cache->getLastSequence());
    }

    public function testStoreOverwritesThePreviousCachedRegistry(): void
    {
        $cache = new PluginRegistryCache($this->path);

        $cache->store($this->registryJson(sequence: 1));
        $cache->store($this->registryJson(sequence: 2));

        $this->assertSame(2, $cache->getLastSequence());
    }

    public function testReturnsNullWhenTheCachedFileIsCorrupt(): void
    {
        file_put_contents($this->path, 'not valid json');

        $cache = new PluginRegistryCache($this->path);

        $this->assertNull($cache->getCachedRegistry());
        $this->assertNull($cache->getLastSequence());
    }

    private function registryJson(int $sequence): string
    {
        return json_encode(['sequence' => $sequence, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR);
    }
}
