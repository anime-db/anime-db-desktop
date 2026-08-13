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
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class FillerRegistryTest extends TestCase
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
}
