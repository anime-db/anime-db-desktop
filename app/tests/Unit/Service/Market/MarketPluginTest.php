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

use AnimeDb\PluginContracts\Manifest\Manifest;
use AnimeDb\PluginContracts\Manifest\ManifestParser;
use App\Entity\ValueObject\PluginId;
use App\Service\Market\MarketPlugin;
use App\Service\Market\MarketPluginVersion;
use PHPUnit\Framework\TestCase;

final class MarketPluginTest extends TestCase
{
    private function manifest(): Manifest
    {
        return (new ManifestParser())->parse((string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.2.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function plugin(): MarketPlugin
    {
        return new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.1.0 <3.0.0'),
            new MarketPluginVersion('1.1.0', '>=2.0.0 <3.0.0'),
        ]);
    }

    public function testLatestVersionIsTheFirstEntry(): void
    {
        $this->assertSame('1.2.0', $this->plugin()->latestVersion()->version);
    }

    public function testResolveCompatibleVersionReturnsTheHighestCompatibleVersion(): void
    {
        $this->assertSame('1.2.0', $this->plugin()->resolveCompatibleVersion('2.5.0', null)?->version);
    }

    public function testResolveCompatibleVersionFallsBackToAnOlderVersionWhenTheLatestIsIncompatible(): void
    {
        $this->assertSame('1.1.0', $this->plugin()->resolveCompatibleVersion('2.0.5', null)?->version);
    }

    public function testResolveCompatibleVersionReturnsNullWhenNoVersionIsCompatible(): void
    {
        $this->assertNull($this->plugin()->resolveCompatibleVersion('1.0.0', null));
    }

    /**
     * Issue #562, criterion "поле удовлетворено": a version whose `pluginContracts` constraint
     * the given plugin-contracts version satisfies is still selected, same as before this axis
     * existed.
     */
    public function testResolveCompatibleVersionSelectsAVersionWhosePluginContractsIsSatisfied(): void
    {
        $plugin = new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.0.0', pluginContracts: '^0.15'),
        ]);

        $this->assertSame('1.2.0', $plugin->resolveCompatibleVersion('2.5.0', '0.15.0')?->version);
    }

    /**
     * Issue #562, criterion "поле не удовлетворено": a version whose `core` is satisfied but
     * whose `pluginContracts` is not must be skipped in favour of an older, actually-compatible
     * one — the resolver keeps scanning down the descending-sorted list rather than stopping at
     * the first `core`-only match.
     */
    public function testResolveCompatibleVersionSkipsAVersionWhosePluginContractsIsNotSatisfied(): void
    {
        $plugin = new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.0.0', pluginContracts: '^0.15'),
            new MarketPluginVersion('1.1.0', '>=2.0.0', pluginContracts: '^0.14'),
        ]);

        $this->assertSame('1.1.0', $plugin->resolveCompatibleVersion('2.5.0', '0.14.2')?->version);
    }

    /**
     * Issue #562, criterion "поле отсутствует": a version that never published a
     * `pluginContracts` constraint is treated as compatible on that axis regardless of the
     * installed plugin-contracts version — the same "unknown = no constraint" rule already
     * applied to `translationKeyCount`/`locales`.
     */
    public function testResolveCompatibleVersionSelectsAVersionWithNoPublishedPluginContracts(): void
    {
        $plugin = new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.0.0'),
        ]);

        $this->assertSame('1.2.0', $plugin->resolveCompatibleVersion('2.5.0', '0.1.0')?->version);
    }

    /**
     * Issue #562, criterion "поле отсутствует у одних версий реестра и присутствует у других":
     * the resolver must pick the highest version, whichever way that particular version's own
     * `pluginContracts` field happens to be populated.
     */
    public function testResolveCompatibleVersionMixesVersionsWithAndWithoutPluginContracts(): void
    {
        $plugin = new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.0.0', pluginContracts: '^0.15'),
            new MarketPluginVersion('1.1.0', '>=2.0.0'),
        ]);

        $this->assertSame('1.1.0', $plugin->resolveCompatibleVersion('2.5.0', '0.1.0')?->version);
        $this->assertSame('1.2.0', $plugin->resolveCompatibleVersion('2.5.0', '0.15.0')?->version);
    }

    /**
     * A `null` installed plugin-contracts version (this app build could not determine it) fails
     * open on this axis, the same convention {@see \App\Service\Plugin\InstalledPluginsRegistry::isCompatible()}
     * already applies for an installed plugin — a version is not blocked by a constraint this app
     * cannot itself evaluate.
     */
    public function testResolveCompatibleVersionFailsOpenWhenTheInstalledPluginContractsVersionIsUnknown(): void
    {
        $plugin = new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.0.0', pluginContracts: '^0.15'),
        ]);

        $this->assertSame('1.2.0', $plugin->resolveCompatibleVersion('2.5.0', null)?->version);
    }

    public function testHasCoreCompatibleVersionIsTrueEvenWhenPluginContractsBlocksEveryVersion(): void
    {
        $plugin = new MarketPlugin(new PluginId('animedb-shikimori'), $this->manifest(), [
            new MarketPluginVersion('1.2.0', '>=2.0.0', pluginContracts: '^0.15'),
        ]);

        $this->assertTrue($plugin->hasCoreCompatibleVersion('2.5.0'));
        $this->assertNull($plugin->resolveCompatibleVersion('2.5.0', '0.10.0'));
    }

    public function testHasCoreCompatibleVersionIsFalseWhenNoVersionsCoreIsSatisfied(): void
    {
        $this->assertFalse($this->plugin()->hasCoreCompatibleVersion('1.0.0'));
    }
}
