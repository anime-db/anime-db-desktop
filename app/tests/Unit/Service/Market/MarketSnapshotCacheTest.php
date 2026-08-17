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

use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotPlugin;
use PHPUnit\Framework\TestCase;

final class MarketSnapshotCacheTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-market-snapshot-cache-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, ...glob($this->path.'.*.tmp') ?: []] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReturnsNullWhenNoCacheFileExists(): void
    {
        $cache = new MarketSnapshotCache($this->path);

        $this->assertNull($cache->load());
    }

    public function testStoresAndRetrievesTheSnapshot(): void
    {
        $cache = new MarketSnapshotCache($this->path);

        $cache->store($this->snapshot());

        $loaded = $cache->load();
        $this->assertNotNull($loaded);
        $this->assertSame('2.5.0', $loaded->coreVersion);
        $this->assertSame(42, $loaded->sequence);
        $this->assertSame(['https://mr01.anime-db.org/<id>/<version>/<file>'], $loaded->assetMirrors);
        $this->assertCount(1, $loaded->plugins);
        $this->assertSame('animedb-shikimori', $loaded->plugins[0]->id);
        $this->assertSame(['id' => 'animedb-shikimori', 'name' => 'Shikimori'], $loaded->plugins[0]->manifest);
        $this->assertSame('1.1.0', $loaded->plugins[0]->resolvedVersion);
        $this->assertSame('sha-1.1.0', $loaded->plugins[0]->sha256);
        $this->assertSame('1.2.0', $loaded->plugins[0]->latestVersion);
    }

    public function testStoreOverwritesThePreviousCachedSnapshot(): void
    {
        $cache = new MarketSnapshotCache($this->path);

        $cache->store($this->snapshot(sequence: 1));
        $cache->store($this->snapshot(sequence: 2));

        $this->assertSame(2, $cache->load()?->sequence);
    }

    public function testReturnsNullWhenTheCachedFileIsCorrupt(): void
    {
        file_put_contents($this->path, 'not valid json');

        $cache = new MarketSnapshotCache($this->path);

        $this->assertNull($cache->load());
    }

    public function testReturnsNullWhenTheCachedFileIsMissingARequiredField(): void
    {
        file_put_contents($this->path, json_encode(['sequence' => 1, 'plugins' => []], \JSON_THROW_ON_ERROR));

        $cache = new MarketSnapshotCache($this->path);

        $this->assertNull($cache->load());
    }

    public function testStoreWritesThroughARandomlyNamedTempFileAndLeavesNoneBehind(): void
    {
        $cache = new MarketSnapshotCache($this->path);

        $cache->store($this->snapshot());

        $this->assertSame([], glob($this->path.'.*.tmp'));
        $this->assertFileExists($this->path);
    }

    public function testThrowsWhenTheCacheDirectoryCannotBeCreated(): void
    {
        // A regular file in place of the cache directory: mkdir() and the subsequent
        // file_put_contents() both fail, so store() must surface that as an exception instead of
        // silently pretending the snapshot was cached.
        $blockingFile = sys_get_temp_dir().'/anime-market-snapshot-cache-test-blocker-'.uniqid();
        file_put_contents($blockingFile, '');
        $cache = new MarketSnapshotCache($blockingFile.'/snapshot.json');

        try {
            $this->expectException(\RuntimeException::class);
            $cache->store($this->snapshot());
        } finally {
            unlink($blockingFile);
        }
    }

    private function snapshot(int $sequence = 42): MarketSnapshot
    {
        return new MarketSnapshot(
            '2.5.0',
            $sequence,
            ['https://mr01.anime-db.org/<id>/<version>/<file>'],
            [
                new MarketSnapshotPlugin(
                    'animedb-shikimori',
                    ['id' => 'animedb-shikimori', 'name' => 'Shikimori'],
                    '1.1.0',
                    'sha-1.1.0',
                    '1.2.0',
                ),
            ],
        );
    }
}
