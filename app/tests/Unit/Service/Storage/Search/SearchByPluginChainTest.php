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

namespace App\Tests\Unit\Service\Storage\Search;

use AnimeDb\PluginContracts\SearchByPluginCandidate;
use AnimeDb\PluginContracts\SearchByPluginInterface;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Storage\Search\NullSearchByPlugin;
use App\Service\Storage\Search\SearchByPluginChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchByPluginChainTest extends TestCase
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

    /**
     * @return array<string, array{string}>
     */
    public static function provideNames(): array
    {
        return [
            'plain title' => ['Bleach'],
            'empty name' => [''],
            'title with special characters' => ["Vivy Fluorite Eye's Song"],
        ];
    }

    #[DataProvider('provideNames')]
    public function testFindReturnsEmptyListWhenOnlyNoOpPluginIsRegistered(string $name): void
    {
        $chain = new SearchByPluginChain(
            ['animedb-null' => new NullSearchByPlugin()],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([], $chain->find($name));
    }

    public function testFindReturnsFirstNonEmptyListAndSkipsRemainingPlugins(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        $first = $this->createStub(SearchByPluginInterface::class);
        $first->method('find')->willReturn($expected);

        $second = $this->createMock(SearchByPluginInterface::class);
        $second->expects($this->never())->method('find');

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $first, 'animedb-anilist' => $second],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindReturnsAllCandidatesFromTheWinningPlugin(): void
    {
        $expected = [
            new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104'),
            new SearchByPluginCandidate('animedb-shikimori', 'Bleach: Thousand-Year Blood War', '205'),
        ];

        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $plugin],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindSkipsPluginsReturningAnEmptyListAndTriesTheNextOne(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        $first = $this->createStub(SearchByPluginInterface::class);
        $first->method('find')->willReturn([]);

        $second = $this->createStub(SearchByPluginInterface::class);
        $second->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $first, 'animedb-anilist' => $second],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindSkipsPluginWithFillerDisabledViaFeaturesFiller(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $disabled = $this->createMock(SearchByPluginInterface::class);
        $disabled->expects($this->never())->method('find');

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $disabled],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame([], $chain->find('Bleach'));
    }

    public function testFindStillTriesAPluginWithFillerExplicitlyEnabled(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => true]],
        ]));

        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $plugin],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindStillTriesAPluginWithoutRecordedFillerSettings(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $plugin],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindStillTriesAPureSearchPluginEvenWhenOtherPluginsFillerIsDisabled(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-mal', 'Bleach', '104')];

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $disabled = $this->createMock(SearchByPluginInterface::class);
        $disabled->expects($this->never())->method('find');

        // Pure search plugin: not registered in plugins.json at all, so features.filler ?? true
        // resolves to true regardless of it never exposing a filler toggle.
        $pureSearch = $this->createStub(SearchByPluginInterface::class);
        $pureSearch->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain(
            ['animedb-shikimori' => $disabled, 'animedb-mal' => $pureSearch],
            new PluginsConfigStore($this->path),
        );

        $this->assertSame($expected, $chain->find('Bleach'));
    }
}
