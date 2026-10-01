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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Filler\CachedFillerLookup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachedFillerLookupTest extends TestCase
{
    public function testASuccessfulResultIsCachedAndTheFillerIsNotCalledAgain(): void
    {
        $filler = $this->createMock(FillerInterface::class);
        $data = new PluginAnimeData(title: 'Bleach');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);

        $lookup = new CachedFillerLookup(new ArrayAdapter());
        $pluginId = new PluginId('animedb-shikimori');

        $first = $lookup->findById($filler, $pluginId, '104');
        $second = $lookup->findById($filler, $pluginId, '104');

        $this->assertSame($data, $first);
        // ArrayAdapter serializes cached values by default, so the second call returns an
        // equal-but-distinct instance — object identity is not what this test is proving;
        // the mock's `expects($this->once())` above is what proves the plugin was not re-asked.
        $this->assertEquals($data, $second);
    }

    public function testANullResultIsNotCachedSoALaterCallRetriesThePlugin(): void
    {
        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->exactly(2))->method('findById')->with('104')->willReturn(null);

        $lookup = new CachedFillerLookup(new ArrayAdapter());
        $pluginId = new PluginId('animedb-shikimori');

        $this->assertNull($lookup->findById($filler, $pluginId, '104'));
        $this->assertNull($lookup->findById($filler, $pluginId, '104'));
    }

    public function testAThrownExceptionIsNotCachedSoALaterCallRetriesThePlugin(): void
    {
        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->exactly(2))->method('findById')->with('104')->willThrowException(new \RuntimeException('unreachable'));

        $lookup = new CachedFillerLookup(new ArrayAdapter());
        $pluginId = new PluginId('animedb-shikimori');

        foreach ([1, 2] as $attempt) {
            try {
                $lookup->findById($filler, $pluginId, '104');
                $this->fail('Expected a RuntimeException.');
            } catch (\RuntimeException) {
                // expected on every attempt — proves nothing got cached
            }
        }
    }

    public function testDifferentPluginsWithTheSameExternalIdDoNotShareACacheEntry(): void
    {
        $dataA = new PluginAnimeData(title: 'From plugin A');
        $fillerA = $this->createStub(FillerInterface::class);
        $fillerA->method('findById')->willReturn($dataA);

        $dataB = new PluginAnimeData(title: 'From plugin B');
        $fillerB = $this->createStub(FillerInterface::class);
        $fillerB->method('findById')->willReturn($dataB);

        $lookup = new CachedFillerLookup(new ArrayAdapter());

        $resultA = $lookup->findById($fillerA, new PluginId('animedb-a'), '104');
        $resultB = $lookup->findById($fillerB, new PluginId('animedb-b'), '104');

        $this->assertSame($dataA, $resultA);
        $this->assertSame($dataB, $resultB);
    }
}
