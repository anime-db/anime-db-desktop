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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        ], \JSON_THROW_ON_ERROR), new NullLogger());

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);

        $plugin = $plugins[0];
        $this->assertSame('animedb-shikimori', (string) $plugin->id);
        $this->assertSame('1.2.0', $plugin->manifest->version);
        $this->assertSame(['1.2.0', '1.1.0'], array_map(static fn ($v) => $v->version, $plugin->versions));
        $this->assertSame('>=2.1 <3.0', $plugin->versions[0]->core);
    }

    /**
     * Issue #514: `translation_keys_count` is carried per-version, alongside `sha256` — a version
     * entry that does not publish it (a plugin published before this field existed) must still
     * parse, just with a `null` count on that {@see \App\Service\Market\MarketPluginVersion}.
     */
    public function testPluginsCarriesOverThePerVersionTranslationKeyCountWhenPresent(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-german',
                    'manifest' => $this->manifest('animedb-german', '1.2.0'),
                    'versions' => [
                        ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => 'abc123', 'translation_keys_count' => 150],
                        ['version' => '1.1.0', 'core' => '>=2.0.0', 'sha256' => 'def456'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), new NullLogger());

        $versions = $registry->plugins()[0]->versions;
        $this->assertSame(150, $versions[0]->translationKeyCount);
        $this->assertNull($versions[1]->translationKeyCount);
    }

    /**
     * Regression guard for issue #514: this fixture mirrors the exact shape of a version entry
     * as published in the real plugins registry (`translation_keys_count`, `core`, `sha256`), so
     * a typo in the field name read by {@see PluginRegistry} fails this test even when every
     * other fixture in the suite agrees with the (wrong) name.
     */
    public function testPluginsReadsTranslationKeyCountFromAPublishedRegistryShapedVersionEntry(): void
    {
        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-language-pack',
                    'manifest' => $this->manifest('animedb-language-pack', '0.2.1'),
                    'versions' => [
                        ['version' => '0.2.1', 'core' => '>=0.0.1', 'sha256' => '2910ece8', 'translation_keys_count' => 356],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), new NullLogger());

        $this->assertSame(356, $registry->plugins()[0]->versions[0]->translationKeyCount);
    }

    /**
     * Issue #539: the sole case this issue treats as loud enough to warrant `error` rather than
     * `warning` — same rationale as {@see \App\Service\Plugin\InstalledPluginsRegistry::reconcile()},
     * which the full structured {@see \AnimeDb\PluginContracts\Manifest\ManifestValidationError}
     * list is carried alongside for.
     */
    public function testPluginsSkipsEntriesWithAnInvalidManifest(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('invalid manifest'),
            $this->callback(static function (array $context): bool {
                if ($context['pluginId'] !== 'animedb-broken' || !\is_array($context['errors']) || $context['errors'] === []) {
                    return false;
                }

                foreach ($context['errors'] as $error) {
                    if (!\is_array($error) || !\array_key_exists('field', $error) || !\array_key_exists('message', $error)) {
                        return false;
                    }
                }

                return true;
            }),
        );
        $logger->expects($this->never())->method('warning');

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
        ], \JSON_THROW_ON_ERROR), $logger);

        $this->assertSame([], $registry->plugins());
    }

    public function testPluginsSkipsRegistryEntriesWithAMissingId(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('missing or malformed id, manifest, or versions'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === null),
        );

        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'manifest' => $this->manifest('animedb-shikimori'),
                    'versions' => [
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), $logger);

        $this->assertSame([], $registry->plugins());
    }

    public function testPluginsSkipsAVersionEntryMissingItsVersionOrCoreField(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('missing or malformed version or core constraint'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'animedb-shikimori'),
        );

        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'animedb-shikimori',
                    'manifest' => $this->manifest('animedb-shikimori'),
                    'versions' => [
                        ['core' => '>=2.0.0', 'sha256' => 'abc123'],
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'def456'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), $logger);

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(['1.0.0'], array_map(static fn ($v) => $v->version, $plugins[0]->versions));
    }

    public function testPluginsSkipsAVersionEntryWithAnInvalidVersionStringButKeepsTheRest(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('unparseable version or core constraint'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'animedb-shikimori' && $context['version'] === 'latest'),
        );

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
        ], \JSON_THROW_ON_ERROR), $logger);

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(['1.0.0'], array_map(static fn ($v) => $v->version, $plugins[0]->versions));
    }

    public function testPluginsSkipsAVersionEntryWithAnInvalidCoreConstraintButKeepsTheRest(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('unparseable version or core constraint'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'animedb-shikimori' && $context['core'] === '~'),
        );

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
        ], \JSON_THROW_ON_ERROR), $logger);

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(['1.0.0'], array_map(static fn ($v) => $v->version, $plugins[0]->versions));
    }

    /**
     * The single invalid version entry is skipped (logged once), which then leaves the plugin
     * with no valid versions at all (logged a second time) — two distinct log entries for the
     * same broken plugin, none for the healthy one that follows it.
     */
    public function testPluginsSkipsAPluginWhoseOnlyVersionIsInvalidWithoutFailingTheWholeRegistry(): void
    {
        $calls = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$calls): void {
            $calls[] = [$message, $context];
        });

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
        ], \JSON_THROW_ON_ERROR), $logger);

        $plugins = $registry->plugins();
        $this->assertCount(1, $plugins);
        $this->assertSame('animedb-shikimori', (string) $plugins[0]->id);

        $this->assertCount(2, $calls);
        $this->assertStringContainsString('unparseable version or core constraint', $calls[0][0]);
        $this->assertSame('animedb-broken-version', $calls[0][1]['pluginId']);
        $this->assertStringContainsString('no valid version entries', $calls[1][0]);
        $this->assertSame('animedb-broken-version', $calls[1][1]['pluginId']);
    }

    public function testPluginsSkipsEntriesWithNoVersions(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('no valid version entries'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'animedb-shikimori'),
        );

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
        ], \JSON_THROW_ON_ERROR), $logger);

        $this->assertSame([], $registry->plugins());
    }

    public function testPluginsSkipsAnEntryWithAnInvalidPluginIdFormat(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('invalid id'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'NotAValidId'),
        );

        $registry = PluginRegistry::fromJson(json_encode([
            'sequence' => 1,
            'asset_mirrors' => [],
            'plugins' => [
                [
                    'id' => 'NotAValidId',
                    'manifest' => $this->manifest('NotAValidId'),
                    'versions' => [
                        ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), $logger);

        $this->assertSame([], $registry->plugins());
    }

    public function testAFullyValidRegistryProducesNoLogEntries(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('error');

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
                [
                    'id' => 'animedb-mal',
                    'manifest' => $this->manifest('animedb-mal', '2.0.0'),
                    'versions' => [
                        ['version' => '2.0.0', 'core' => '>=1.0.0', 'sha256' => 'ghi789'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), $logger);

        $this->assertCount(2, $registry->plugins());
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
        ], \JSON_THROW_ON_ERROR), new NullLogger());

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
        ], \JSON_THROW_ON_ERROR), new NullLogger());

        $this->assertNull($registry->findVersionSha256(new PluginId('animedb-shikimori'), '9.9.9'));
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson('{not valid json', new NullLogger());
    }

    public function testThrowsWhenSequenceIsMissing(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR), new NullLogger());
    }

    public function testThrowsWhenSequenceIsNotAPositiveInteger(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['sequence' => 0, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR), new NullLogger());
    }

    public function testThrowsWhenAssetMirrorsIsMissing(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['sequence' => 1, 'plugins' => []], \JSON_THROW_ON_ERROR), new NullLogger());
    }

    public function testThrowsWhenPluginsIsMissing(): void
    {
        $this->expectException(InvalidPluginRegistryContentException::class);

        PluginRegistry::fromJson(json_encode(['sequence' => 1, 'asset_mirrors' => []], \JSON_THROW_ON_ERROR), new NullLogger());
    }

    private function minimalRegistry(): PluginRegistry
    {
        return PluginRegistry::fromJson(json_encode(['sequence' => 1, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR), new NullLogger());
    }
}
