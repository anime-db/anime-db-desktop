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

use AnimeDb\PluginContracts\Manifest\ManifestParser;
use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotPlugin;
use App\Service\Market\MarketUpdateResolver;
use App\Service\Plugin\InstalledPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarketUpdateResolverTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-market-update-resolver-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testReturnsTheResolvedVersionWhenNewerThanInstalled(): void
    {
        $this->storeSnapshot(self::CORE_VERSION, [$this->snapshotPlugin('animedb-shikimori', '1.2.0')]);

        self::assertSame('1.2.0', $this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    public function testReturnsNullWhenResolvedVersionEqualsInstalled(): void
    {
        $this->storeSnapshot(self::CORE_VERSION, [$this->snapshotPlugin('animedb-shikimori', '1.1.0')]);

        self::assertNull($this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    public function testReturnsNullWhenResolvedVersionIsOlderThanInstalled(): void
    {
        $this->storeSnapshot(self::CORE_VERSION, [$this->snapshotPlugin('animedb-shikimori', '1.0.0')]);

        self::assertNull($this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    public function testReturnsNullWithoutASnapshot(): void
    {
        self::assertNull($this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    public function testReturnsNullWhenSnapshotWasBuiltForAnotherCoreVersion(): void
    {
        $this->storeSnapshot('2.4.0', [$this->snapshotPlugin('animedb-shikimori', '1.2.0')]);

        self::assertNull($this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    public function testReturnsNullWhenThePluginIsNotInTheSnapshot(): void
    {
        $this->storeSnapshot(self::CORE_VERSION, [$this->snapshotPlugin('animedb-anilist', '9.0.0')]);

        self::assertNull($this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    public function testReturnsNullWhenThePluginHasNoCompatibleVersion(): void
    {
        $this->storeSnapshot(self::CORE_VERSION, [$this->snapshotPlugin('animedb-shikimori', null)]);

        self::assertNull($this->resolver()->availableUpdate($this->installed('1.1.0')));
    }

    #[DataProvider('versionComparisons')]
    public function testIsNewerVersion(string $resolved, string $installed, bool $expected): void
    {
        self::assertSame($expected, $this->resolver()->isNewerVersion($resolved, $installed));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function versionComparisons(): iterable
    {
        yield 'newer' => ['1.2.0', '1.1.0', true];
        yield 'older' => ['1.0.0', '1.1.0', false];
        yield 'equal' => ['1.1.0', '1.1.0', false];
        yield 'equal but differently formatted' => ['1.0', '1.0.0', false];
    }

    private function resolver(): MarketUpdateResolver
    {
        return new MarketUpdateResolver(new MarketSnapshotCache($this->path), self::CORE_VERSION);
    }

    /**
     * @param list<MarketSnapshotPlugin> $plugins
     */
    private function storeSnapshot(string $coreVersion, array $plugins): void
    {
        (new MarketSnapshotCache($this->path))->store(new MarketSnapshot($coreVersion, 1, [], $plugins));
    }

    private function snapshotPlugin(string $id, ?string $resolvedVersion): MarketSnapshotPlugin
    {
        return new MarketSnapshotPlugin($id, ['id' => $id], $resolvedVersion, $resolvedVersion !== null ? 'sha' : null, '9.9.9', '>=2.0.0');
    }

    private function installed(string $version): InstalledPlugin
    {
        $manifest = (new ManifestParser())->parse((string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        return new InstalledPlugin($manifest, '/tmp/plugin', false, false);
    }
}
