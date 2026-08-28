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

use App\Service\Market\MarketSnapshotBuilder;
use App\Service\Market\PluginRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MarketSnapshotBuilderTest extends TestCase
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

    private function registry(): PluginRegistry
    {
        return PluginRegistry::fromJson(json_encode([
            'sequence' => 42,
            'asset_mirrors' => [
                'https://mr01.anime-db.org/<id>/<version>/<file>',
                'https://mr02.anime-db.org/<id>/<version>/<file>',
            ],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori', '1.2.0'),
                    'versions' => [
                        ['version' => '1.2.0', 'core' => '>=3.0.0', 'sha256' => 'sha-1.2.0'],
                        ['version' => '1.1.0', 'core' => '>=2.0.0 <3.0.0', 'sha256' => 'sha-1.1.0'],
                    ],
                ],
                [
                    'id' => 'animedb-incompatible',
                    'manifest' => $this->manifest('animedb-incompatible', '2.0.0'),
                    'versions' => [
                        ['version' => '2.0.0', 'core' => '>=99.0.0', 'sha256' => 'sha-2.0.0'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), new NullLogger());
    }

    public function testCarriesOverCoreVersionSequenceAndAssetMirrorsUnchanged(): void
    {
        $snapshot = (new MarketSnapshotBuilder())->build($this->registry(), '2.5.0');

        $this->assertSame('2.5.0', $snapshot->coreVersion);
        $this->assertSame(42, $snapshot->sequence);
        $this->assertSame([
            'https://mr01.anime-db.org/<id>/<version>/<file>',
            'https://mr02.anime-db.org/<id>/<version>/<file>',
        ], $snapshot->assetMirrors);
    }

    public function testResolvesTheHighestCompatibleVersionAndItsSha256(): void
    {
        $snapshot = (new MarketSnapshotBuilder())->build($this->registry(), '2.5.0');

        $plugin = $snapshot->plugins[0];
        $this->assertSame('animedb-shikimori', $plugin->id);
        $this->assertSame('1.1.0', $plugin->resolvedVersion);
        $this->assertSame('sha-1.1.0', $plugin->sha256);
        $this->assertSame('1.2.0', $plugin->latestVersion);
        $this->assertSame('>=3.0.0', $plugin->latestVersionCore);
        $this->assertSame('animedb-shikimori', $plugin->manifest['id']);
    }

    public function testAPluginWithNoCompatibleVersionIsStillIncludedWithNullResolvedVersionAndSha256(): void
    {
        $snapshot = (new MarketSnapshotBuilder())->build($this->registry(), '2.5.0');

        $plugin = $snapshot->plugins[1];
        $this->assertSame('animedb-incompatible', $plugin->id);
        $this->assertNull($plugin->resolvedVersion);
        $this->assertNull($plugin->sha256);
        $this->assertSame('2.0.0', $plugin->latestVersion);
        $this->assertSame('>=99.0.0', $plugin->latestVersionCore);
    }

    public function testAnEmptyRegistryBuildsAnEmptyPluginsList(): void
    {
        $registry = PluginRegistry::fromJson(json_encode(['sequence' => 1, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR), new NullLogger());

        $snapshot = (new MarketSnapshotBuilder())->build($registry, '2.5.0');

        $this->assertSame([], $snapshot->plugins);
    }

    /**
     * Issue #514's core acceptance criterion: {@see MarketPlugin::resolveCompatibleVersion()} can
     * fall back to an older-than-latest version (see
     * {@see testResolvesTheHighestCompatibleVersionAndItsSha256()}), and that older version may
     * carry a different `translation_keys_count` than the latest one. The snapshot must carry the
     * count of the version it actually resolved, never the latest one's, or the badge would
     * describe an artifact the storefront would not even install.
     */
    public function testTranslationKeyCountIsTakenFromTheResolvedVersionNotTheLatestOne(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-german',
                    'manifest' => $this->manifest('animedb-german', '1.2.0'),
                    'versions' => [
                        ['version' => '1.2.0', 'core' => '>=3.0.0', 'sha256' => 'sha-1.2.0', 'translation_keys_count' => 200],
                        ['version' => '1.1.0', 'core' => '>=2.0.0 <3.0.0', 'sha256' => 'sha-1.1.0', 'translation_keys_count' => 120],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), new NullLogger());

        $snapshot = (new MarketSnapshotBuilder())->build($registry, '2.5.0');

        $plugin = $snapshot->plugins[0];
        $this->assertSame('1.1.0', $plugin->resolvedVersion);
        $this->assertSame(120, $plugin->translationKeyCount);
    }

    public function testTranslationKeyCountIsNullWhenTheRegistryVersionEntryDoesNotCarryOne(): void
    {
        $snapshot = (new MarketSnapshotBuilder())->build($this->registry(), '2.5.0');

        $this->assertNull($snapshot->plugins[0]->translationKeyCount);
    }

    /**
     * Issue #543's core acceptance criterion: the storefront must show the languages of the
     * version the resolver actually selected, not the latest published one — the same
     * $resolvedVersion-over-latestVersion rule {@see testTranslationKeyCountIsTakenFromTheResolvedVersionNotTheLatestOne()}
     * already enforces for the translation coverage figure.
     */
    public function testLocalesAreTakenFromTheResolvedVersionNotTheLatestOne(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-german',
                    'manifest' => $this->manifest('animedb-german', '1.2.0'),
                    'versions' => [
                        ['version' => '1.2.0', 'core' => '>=3.0.0', 'sha256' => 'sha-1.2.0', 'locales' => ['de', 'ja']],
                        ['version' => '1.1.0', 'core' => '>=2.0.0 <3.0.0', 'sha256' => 'sha-1.1.0', 'locales' => ['de']],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), new NullLogger());

        $snapshot = (new MarketSnapshotBuilder())->build($registry, '2.5.0');

        $plugin = $snapshot->plugins[0];
        $this->assertSame('1.1.0', $plugin->resolvedVersion);
        $this->assertSame(['de'], $plugin->locales);
    }

    public function testLocalesIsNullWhenTheRegistryVersionEntryDoesNotCarryOne(): void
    {
        $snapshot = (new MarketSnapshotBuilder())->build($this->registry(), '2.5.0');

        $this->assertNull($snapshot->plugins[0]->locales);
    }
}
