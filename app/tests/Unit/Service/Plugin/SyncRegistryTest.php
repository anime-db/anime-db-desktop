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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use PHPUnit\Framework\TestCase;

final class SyncRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createSync(): SyncInterface
    {
        return $this->createStub(SyncInterface::class);
    }

    public function testFindByPluginIdReturnsTheMatchingActiveSync(): void
    {
        $shikimori = $this->createSync();
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $shikimori],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($shikimori, $registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testFindByPluginIdReturnsNullWhenNoSyncIsRegisteredUnderThatId(): void
    {
        $registry = new SyncRegistry(
            ['animedb-shikimori' => $this->createSync()],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->findByPluginId(new PluginId('animedb-anilist')));
    }

    public function testFindByPluginIdReturnsNullWhenPluginWithoutRecordedSettingsIsInactiveByDefault(): void
    {
        $registry = new SyncRegistry(
            ['animedb-shikimori' => $this->createSync()],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testFindByPluginIdReturnsNullWhenSyncIsExplicitlyDisabled(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => false]],
        ]));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $this->createSync()],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testFindByPluginIdReturnsNullWhenThePluginDoesNotImplementSyncInterface(): void
    {
        $registry = new SyncRegistry(
            [],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testAllActiveReturnsOnlyActiveSyncs(): void
    {
        $shikimori = $this->createSync();
        $anilist = $this->createSync();
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
            'animedb-anilist' => ['features' => ['sync' => false]],
        ]));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $shikimori, 'animedb-anilist' => $anilist, 'animedb-mal' => $this->createSync()],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame(['animedb-shikimori' => $shikimori], iterator_to_array($registry->allActive()));
    }

    public function testAllActiveReturnsEmptyWhenNothingIsExplicitlyEnabled(): void
    {
        $registry = new SyncRegistry(
            ['animedb-shikimori' => $this->createSync()],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([], iterator_to_array($registry->allActive()));
    }
}
