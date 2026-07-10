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

use App\Entity\ValueObject\PluginId;
use App\Service\Storage\Search\NullSearchByPlugin;
use App\Service\Storage\Search\SearchByPluginCandidate;
use App\Service\Storage\Search\SearchByPluginChain;
use App\Service\Storage\Search\SearchByPluginInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchByPluginChainTest extends TestCase
{
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
        $chain = new SearchByPluginChain([new NullSearchByPlugin()]);

        $this->assertSame([], $chain->find($name));
    }

    public function testFindReturnsFirstNonEmptyListAndSkipsRemainingPlugins(): void
    {
        $expected = [new SearchByPluginCandidate(new PluginId('animedb-shikimori'), 'Bleach')];

        $first = $this->createStub(SearchByPluginInterface::class);
        $first->method('find')->willReturn($expected);

        $second = $this->createMock(SearchByPluginInterface::class);
        $second->expects($this->never())->method('find');

        $chain = new SearchByPluginChain([$first, $second]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindReturnsAllCandidatesFromTheWinningPlugin(): void
    {
        $expected = [
            new SearchByPluginCandidate(new PluginId('animedb-shikimori'), 'Bleach'),
            new SearchByPluginCandidate(new PluginId('animedb-shikimori'), 'Bleach: Thousand-Year Blood War'),
        ];

        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain([$plugin]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindSkipsPluginsReturningAnEmptyListAndTriesTheNextOne(): void
    {
        $expected = [new SearchByPluginCandidate(new PluginId('animedb-shikimori'), 'Bleach')];

        $first = $this->createStub(SearchByPluginInterface::class);
        $first->method('find')->willReturn([]);

        $second = $this->createStub(SearchByPluginInterface::class);
        $second->method('find')->willReturn($expected);

        $chain = new SearchByPluginChain([$first, $second]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }
}
