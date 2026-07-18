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

use AnimeDb\PluginContracts\CatalogWidgetInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class CatalogWidgetRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-widgets-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testFindReturnsTheMatchingWidgetForACompoundPluginAndWidgetNameKey(): void
    {
        $newReleases = $this->createStub(CatalogWidgetInterface::class);

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $newReleases],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($newReleases, $registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindReturnsNullWhenNoWidgetIsRegisteredUnderThatKey(): void
    {
        $registry = new CatalogWidgetRegistry([], new PluginsConfigStore($this->path));

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindReturnsNullWhenTheWidgetIsDisabled(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class)],
            new PluginsConfigStore($this->path),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }
}
