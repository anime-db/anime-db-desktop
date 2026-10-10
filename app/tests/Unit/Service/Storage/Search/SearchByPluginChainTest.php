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

namespace App\Tests\Unit\Service\Storage\Search;

use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\DefaultSearchPluginRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Storage\Search\SearchByPluginChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchByPluginChainTest extends TestCase
{
    private string $path;
    private string $configPath;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'.json';
        $this->configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock', $this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
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
    public function testFindReturnsEmptyListWhenChainIsEmpty(string $name): void
    {
        $chain = $this->chain([]);

        $this->assertSame([], $chain->find($name));
    }

    public function testFindReturnsFirstNonEmptyListAndSkipsRemainingPlugins(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        $first = $this->createStub(SearchByPluginInterface::class);
        $first->method('find')->willReturn($expected);

        $second = $this->createMock(SearchByPluginInterface::class);
        $second->expects($this->never())->method('find');

        $chain = $this->chain(['animedb-shikimori' => $first, 'animedb-anilist' => $second]);

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

        $chain = $this->chain(['animedb-shikimori' => $plugin]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindSkipsPluginsReturningAnEmptyListAndTriesTheNextOne(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        $first = $this->createStub(SearchByPluginInterface::class);
        $first->method('find')->willReturn([]);

        $second = $this->createStub(SearchByPluginInterface::class);
        $second->method('find')->willReturn($expected);

        $chain = $this->chain(['animedb-shikimori' => $first, 'animedb-anilist' => $second]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindSkipsPluginWithFillerDisabledViaFeaturesFiller(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['filler' => false]],
        ]));

        $disabled = $this->createMock(SearchByPluginInterface::class);
        $disabled->expects($this->never())->method('find');

        $chain = $this->chain(['animedb-shikimori' => $disabled]);

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

        $chain = $this->chain(['animedb-shikimori' => $plugin]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindStillTriesAPluginWithoutRecordedFillerSettings(): void
    {
        $expected = [new SearchByPluginCandidate('animedb-shikimori', 'Bleach', '104')];

        $plugin = $this->createStub(SearchByPluginInterface::class);
        $plugin->method('find')->willReturn($expected);

        $chain = $this->chain(['animedb-shikimori' => $plugin]);

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

        $chain = $this->chain(['animedb-shikimori' => $disabled, 'animedb-mal' => $pureSearch]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindAsksTheSelectedPluginFirst(): void
    {
        $order = [];
        $chain = $this->chainRecordingOrder(['pl-a', 'pl-b', 'pl-c'], $order);
        $this->settings()->setDefaultSearchPluginId(new PluginId('pl-b'));

        $this->assertSame([], $chain->find('Bleach'));
        $this->assertSame(['pl-b', 'pl-a', 'pl-c'], $order);
    }

    public function testFindStopsAtTheSelectedPluginWhenItAnswers(): void
    {
        $expected = [new SearchByPluginCandidate('pl-b', 'Bleach', '1')];
        $a = $this->createMock(SearchByPluginInterface::class);
        $a->expects($this->never())->method('find');
        $b = $this->createStub(SearchByPluginInterface::class);
        $b->method('find')->willReturn($expected);
        $this->settings()->setDefaultSearchPluginId(new PluginId('pl-b'));

        $chain = $this->chain(['pl-a' => $a, 'pl-b' => $b]);

        $this->assertSame($expected, $chain->find('Bleach'));
    }

    public function testFindFallsBackToTheRestInOriginalOrderWhenSelectedReturnsNothing(): void
    {
        $expected = [new SearchByPluginCandidate('pl-c', 'Bleach', '1')];
        $calls = [];
        $make = function (string $id, array $result) use (&$calls): SearchByPluginInterface {
            $plugin = $this->createStub(SearchByPluginInterface::class);
            $plugin->method('find')->willReturnCallback(static function () use ($id, $result, &$calls): array {
                $calls[] = $id;

                return $result;
            });

            return $plugin;
        };
        $this->settings()->setDefaultSearchPluginId(new PluginId('pl-b'));
        $chain = $this->chain(['pl-a' => $make('pl-a', []), 'pl-b' => $make('pl-b', []), 'pl-c' => $make('pl-c', $expected)]);

        $this->assertSame($expected, $chain->find('Bleach'));
        $this->assertSame(['pl-b', 'pl-a', 'pl-c'], $calls);
    }

    public function testFindKeepsRegistrationOrderAndWritesNothingWithoutASelection(): void
    {
        $order = [];
        $chain = $this->chainRecordingOrder(['pl-a', 'pl-b', 'pl-c'], $order);

        $chain->find('Bleach');

        $this->assertSame(['pl-a', 'pl-b', 'pl-c'], $order);
        $this->assertFileDoesNotExist($this->configPath);
    }

    public function testFindKeepsRegistrationOrderWhenSelectedPluginIsNotInstalled(): void
    {
        $order = [];
        $chain = $this->chainRecordingOrder(['pl-a', 'pl-b', 'pl-c'], $order);
        $this->settings()->setDefaultSearchPluginId(new PluginId('pl-gone'));
        $before = file_get_contents($this->configPath);

        $chain->find('Bleach');

        $this->assertSame(['pl-a', 'pl-b', 'pl-c'], $order);
        $this->assertSame($before, file_get_contents($this->configPath));
        $this->assertSame('pl-gone', (string) $this->settings()->getDefaultSearchPluginId());
    }

    public function testFindSkipsSelectedPluginWithFillerDisabledAndKeepsOrder(): void
    {
        file_put_contents($this->path, json_encode(['pl-b' => ['features' => ['filler' => false]]]));
        $order = [];
        $chain = $this->chainRecordingOrder(['pl-a', 'pl-b', 'pl-c'], $order);
        $this->settings()->setDefaultSearchPluginId(new PluginId('pl-b'));
        $before = file_get_contents($this->configPath);

        $chain->find('Bleach');

        $this->assertSame(['pl-a', 'pl-c'], $order);
        $this->assertSame($before, file_get_contents($this->configPath));
    }

    /** @param array<string, SearchByPluginInterface> $plugins */
    private function chain(array $plugins): SearchByPluginChain
    {
        $store = new PluginsConfigStore($this->path);

        return new SearchByPluginChain($plugins, $store, new DefaultSearchPluginRegistry($plugins, $store, $this->settings()));
    }

    private function settings(): AppSettingsProvider
    {
        return new AppSettingsProvider(new AppConfigStore($this->configPath));
    }

    /**
     * @param list<string> $ids
     * @param list<string> $order filled with the ids of the plugins asked, in call order
     */
    private function chainRecordingOrder(array $ids, array &$order): SearchByPluginChain
    {
        $plugins = [];
        foreach ($ids as $id) {
            $plugin = $this->createStub(SearchByPluginInterface::class);
            $plugin->method('find')->willReturnCallback(static function () use ($id, &$order): array {
                $order[] = $id;

                return [];
            });
            $plugins[$id] = $plugin;
        }

        return $this->chain($plugins);
    }
}
