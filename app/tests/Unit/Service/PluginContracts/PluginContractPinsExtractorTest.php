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

namespace App\Tests\Unit\Service\PluginContracts;

use App\Service\Market\PluginRegistry;
use App\Service\PluginContracts\PluginContractPinsExtractor;
use App\Service\PluginContracts\PluginContractsCheckException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PluginContractPinsExtractorTest extends TestCase
{
    public function testExtractsPinsOfParsedPlugin(): void
    {
        $json = $this->registryJson([$this->plugin('animedb-shikimori', [['0.9.1', '^0.21'], ['0.9.2', null]])]);

        $pins = $this->extract($json);

        self::assertCount(1, $pins);
        self::assertTrue($pins[0]->parsed);
        self::assertSame('0.9.2', $pins[0]->latestVersion);
        self::assertNull($pins[0]->latestPin);
        self::assertEqualsCanonicalizing(['^0.21', null], $pins[0]->pins);
    }

    public function testPluginDroppedByTheParserIsReportedAsNotParsedWithRawVersionAndPin(): void
    {
        $broken = $this->plugin('animedb-broken', [['1.0.0', '^0.21'], ['1.1.0', '^0.22']]);
        $broken['manifest'] = ['id' => 'animedb-broken'];

        $pins = $this->extract($this->registryJson([$broken]));

        self::assertCount(1, $pins);
        self::assertFalse($pins[0]->parsed);
        self::assertSame('animedb-broken', $pins[0]->id);
        self::assertSame('1.1.0', $pins[0]->latestVersion);
        self::assertSame('^0.22', $pins[0]->latestPin);
    }

    public function testPinDiscardedByTheParserIsACheckError(): void
    {
        $this->expectException(PluginContractsCheckException::class);

        $this->extract($this->registryJson([$this->plugin('animedb-shikimori', [['0.9.1', '^^broken']])]));
    }

    public function testEmptyStringPinIsACheckError(): void
    {
        $this->expectException(PluginContractsCheckException::class);

        $this->extract($this->registryJson([$this->plugin('animedb-shikimori', [['0.9.1', '']])]));
    }

    public function testPluginEntryWithoutIdIsACheckError(): void
    {
        $this->expectException(PluginContractsCheckException::class);

        $this->extract($this->registryJson([['versions' => []]]));
    }

    public function testExtractAllReportsBrokenPinOnThatPluginOnly(): void
    {
        $json = $this->registryJson([
            $this->plugin('animedb-broken', [['0.9.1', '^^broken']]),
            $this->plugin('animedb-healthy', [['1.0.0', '^0.22']]),
        ]);

        $extraction = (new PluginContractPinsExtractor())->extractAll($json, PluginRegistry::fromJson($json, new NullLogger()));

        $byId = [];
        foreach ($extraction->plugins as $pins) {
            $byId[$pins->id] = $pins;
        }
        self::assertNotNull($byId['animedb-broken']->problem);
        self::assertNull($byId['animedb-healthy']->problem);
        self::assertSame(['^0.22'], $byId['animedb-healthy']->pins);
        self::assertSame(0, $extraction->entriesWithoutId);
    }

    public function testExtractAllCountsEntriesWithoutIdInsteadOfThrowing(): void
    {
        $json = $this->registryJson([['versions' => []], $this->plugin('animedb-healthy', [['1.0.0', '^0.22']])]);

        $extraction = (new PluginContractPinsExtractor())->extractAll($json, PluginRegistry::fromJson($json, new NullLogger()));

        self::assertSame(1, $extraction->entriesWithoutId);
        self::assertCount(1, $extraction->plugins);
    }

    public function testExtractAllStillThrowsOnUnreadableRegistry(): void
    {
        $this->expectException(PluginContractsCheckException::class);

        (new PluginContractPinsExtractor())->extractAll('{"plugins": 1}', PluginRegistry::fromJson($this->registryJson([]), new NullLogger()));
    }

    /**
     * @return list<\App\Service\PluginContracts\PluginContractPins>
     */
    private function extract(string $json): array
    {
        return (new PluginContractPinsExtractor())->extract($json, PluginRegistry::fromJson($json, new NullLogger()));
    }

    /**
     * @param list<array<string, mixed>> $plugins
     */
    private function registryJson(array $plugins): string
    {
        return json_encode(['sequence' => 7, 'asset_mirrors' => [], 'plugins' => $plugins], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{string, string|null}> $versions
     *
     * @return array<string, mixed>
     */
    private function plugin(string $id, array $versions): array
    {
        return [
            'id' => $id,
            'manifest' => [
                'id' => $id,
                'name' => ucfirst($id),
                'version' => '1.0.0',
                'type' => 'integration',
                'features' => ['filler' => true],
                'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            ],
            'versions' => array_map(static fn (array $v): array => [
                'version' => $v[0],
                'core' => '>=2.0.0',
                'sha256' => str_repeat('a', 64),
                'plugin_contracts' => $v[1],
            ], $versions),
        ];
    }
}
