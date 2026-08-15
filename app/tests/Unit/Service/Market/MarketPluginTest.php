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
use App\Entity\ValueObject\PluginId;
use App\Service\Market\MarketPlugin;
use App\Service\Market\MarketPluginVersion;
use PHPUnit\Framework\TestCase;

final class MarketPluginTest extends TestCase
{
    private function plugin(): MarketPlugin
    {
        $manifest = (new ManifestParser())->parse((string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.2.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        return new MarketPlugin(new PluginId('animedb-shikimori'), $manifest, [
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
        $this->assertSame('1.2.0', $this->plugin()->resolveCompatibleVersion('2.5.0')?->version);
    }

    public function testResolveCompatibleVersionFallsBackToAnOlderVersionWhenTheLatestIsIncompatible(): void
    {
        $this->assertSame('1.1.0', $this->plugin()->resolveCompatibleVersion('2.0.5')?->version);
    }

    public function testResolveCompatibleVersionReturnsNullWhenNoVersionIsCompatible(): void
    {
        $this->assertNull($this->plugin()->resolveCompatibleVersion('1.0.0'));
    }
}
