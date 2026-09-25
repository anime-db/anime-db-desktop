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

use App\Service\PluginContracts\LagReason;
use App\Service\PluginContracts\PluginContractPins;
use App\Service\PluginContracts\PluginContractsCheckException;
use App\Service\PluginContracts\PluginContractsLagDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginContractsLagDetectorTest extends TestCase
{
    public function testPluginWhoseEveryVersionPinsAnOlderMinorLags(): void
    {
        $lagging = (new PluginContractsLagDetector())->detect('0.22.0', [
            new PluginContractPins('animedb-shikimori', true, ['^0.21', '^0.21', '^0.21'], '0.9.2', '^0.21'),
        ]);

        self::assertCount(1, $lagging);
        self::assertSame('animedb-shikimori', $lagging[0]->id);
        self::assertSame(LagReason::NO_ACCEPTING_VERSION, $lagging[0]->reason);
        self::assertSame('0.9.2', $lagging[0]->latestVersion);
        self::assertSame('^0.21', $lagging[0]->latestPin);
    }

    public function testPluginAheadOfTheAppDoesNotLagWhileAnOlderVersionAcceptsIt(): void
    {
        $lagging = (new PluginContractsLagDetector())->detect('0.22.0', [
            new PluginContractPins('animedb-shikimori', true, ['^0.23', '^0.22'], '0.10.0', '^0.23'),
        ]);

        self::assertSame([], $lagging);
    }

    public function testVersionWithoutPinAcceptsAnyContractsVersion(): void
    {
        $lagging = (new PluginContractsLagDetector())->detect('0.22.0', [
            new PluginContractPins('animedb-language-pack', true, [null], '1.0.0', null),
        ]);

        self::assertSame([], $lagging);
    }

    public function testDroppedPluginLagsWithManifestReason(): void
    {
        $lagging = (new PluginContractsLagDetector())->detect('0.22.0', [
            new PluginContractPins('animedb-broken', false, [], '1.0.0', '^0.22'),
        ]);

        self::assertCount(1, $lagging);
        self::assertSame(LagReason::MANIFEST_NOT_PARSEABLE, $lagging[0]->reason);
        self::assertSame('1.0.0', $lagging[0]->latestVersion);
    }

    public function testEmptyRegistryHasNoLaggingPlugins(): void
    {
        self::assertSame([], (new PluginContractsLagDetector())->detect('0.22.0', []));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unusableAppVersions(): iterable
    {
        yield 'unknown' => [null];
        yield 'empty' => [''];
        yield 'dev branch' => ['dev-master'];
        yield 'dev suffix' => ['0.22.x-dev'];
        yield 'beta' => ['0.22.0-beta1'];
        yield 'unparseable' => ['not a version'];
    }

    #[DataProvider('unusableAppVersions')]
    public function testUnusableAppVersionRefusesToAnswer(?string $version): void
    {
        $this->expectException(PluginContractsCheckException::class);

        (new PluginContractsLagDetector())->detect($version, [
            new PluginContractPins('animedb-shikimori', true, ['^0.22'], '0.9.3', '^0.22'),
        ]);
    }
}
