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

use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\InvalidPluginRegistryContentException;
use App\Service\Market\PluginRegistry;
use PHPUnit\Framework\TestCase;

final class PluginRegistryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifest(string $id, string $version = '1.0.0'): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($id),
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ];
    }

    public function testPluginsExposesTheStorefrontCatalogSortedByVersionDescending(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori', '1.2.0'),
                    'versions' => [
                        ['version' => '1.1.0', 'core' => '>=2.0 <3.0', 'sha256' => 'def456'],
                        ['version' => '1.2.0', 'core' => '>=2.1 <3.0', 'sha256' => 'abc123'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);

        $plugin = $plugins[0];
        $this->assertSame('animedb-shikimori', (string) $plugin->id);
        $this->assertSame('1.2.0', $plugin->manifest->version);
        $this->assertSame(['1.2.0', '1.1.0'], array_map(static fn ($v) => $v->version, $plugin->versions));
        $this->assertSame('>=2.1 <3.0', $plugin->versions[0]->core);
    }

    public function testPluginsSkipsEntriesWithAnInvalidManifest(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-broken',
                    'manifest' => ['id' => 'animedb-broken'],
                    'versions' => [
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->assertSame([], $registry->plugins());
    }

    public function testPluginsSkipsAVersionEntryWithAnInvalidVersionStringButKeepsTheRest(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori', '1.0.0'),
                    'versions' => [
                        ['version' => 'latest', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'def456'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(['1.0.0'], array_map(static fn ($v) => $v->version, $plugins[0]->versions));
    }

    public function testPluginsSkipsAVersionEntryWithAnInvalidCoreConstraintButKeepsTheRest(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori', '1.0.0'),
                    'versions' => [
                        ['version' => '1.1.0', 'core' => '~', 'sha256' => 'abc123'],
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'def456'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(['1.0.0'], array_map(static fn ($v) => $v->version, $plugins[0]->versions));
    }

    public function testPluginsSkipsAPluginWhoseOnlyVersionIsInvalidWithoutFailingTheWholeRegistry(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-broken-version',
                    'manifest' => $this->manifest('animedb-broken-version', '1.0.0'),
                    'versions' => [
                        ['version' => 'latest', 'core' => '~', 'sha256' => 'abc123'],
                    ],
                ],
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori', '1.0.0'),
                    'versions' => [
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'def456'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame('animedb-shikimori', (string) $plugins[0]->id);
    }

    public function testPluginsSkipsEntriesWithNoVersions(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori'),
                    'versions' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->assertSame([], $registry->plugins());
    }

    public function testParsesSequenceAssetMirrorsAndVersionChecksums(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 42,
            'asset_mirrors' => [
                'https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>',
                'https://mr01.anime-db.org/<id>/<version>/<file>',
            ],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => ['id' => 'animedb-shikimori'],
                    'versions' => [
                        ['version' => '1.2.0', 'core' => '>=2.1 <3.0', 'sha256' => 'abc123'],
                        ['version' => '1.1.0', 'core' => '>=2.0 <3.0', 'sha256' => 'def456'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->assertSame(42, $registry->sequence);
        $this->assertSame([
            'https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>',
            'https://mr01.anime-db.org/<id>/<version>/<file>',
        ], $registry->assetMirrors);
        $this->assertSame('abc123', $registry->findVersionSha256(new PluginId('animedb-shikimori'), '1.2.0'));
        $this->assertSame('def456', $registry->findVersionSha256(new PluginId('animedb-shikimori'), '1.1.0'));
    }

    public function testFindVersionSha256ReturnsNullForUnknownPlugin(): void
    {
        $registry = $this->minimalRegistry();

        $this->assertNull($registry->findVersionSha256(new PluginId('unknown-plugin'), '1.0.0'));
    }

    public function testFindVersionSha256ReturnsNullForUnknownVersion(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'versions' => [
                        ['version' => '1.0.0', 'core' => '>=1.0', 'sha256' => 'abc123'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->assertNull($registry->findVersionSha256(new PluginId('animedb-shikimori'), '9.9.9'));
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson('{not valid json');
    }

    public function testThrowsWhenSequenceIsMissing(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR));
    }

    public function testThrowsWhenSequenceIsNotAPositiveInteger(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['sequence' => 0, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR));
    }

    public function testThrowsWhenAssetMirrorsIsMissing(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['sequence' => 1, 'plugins' => []], \JSON_THROW_ON_ERROR));
    }

    public function testThrowsWhenPluginsIsMissing(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['sequence' => 1, 'asset_mirrors' => []], \JSON_THROW_ON_ERROR));
    }

    private function minimalRegistry(): PluginRegistry
    {
        return PluginRegistry::fromJson(json_encode(['sequence' => 1, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR));
    }
}
