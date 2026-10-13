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
use App\Entity\TvAnime;
use App\Service\Plugin\Filler\SaturationAvailability;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SaturationAvailabilityTest extends TestCase
{
    /** @param array<string, FillerInterface> $fillers */
    private function availability(array $fillers): SaturationAvailability
    {
        return new SaturationAvailability(
            new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-saturation-availability-'.uniqid().'.json')),
            new NullLogger(),
        );
    }

    private function filler(?string $resolved, bool $throws = false): FillerInterface
    {
        $filler = $this->createStub(FillerInterface::class);
        $method = $filler->method('resolveExternalId');
        $throws ? $method->willThrowException(new \RuntimeException('broken plugin')) : $method->willReturn($resolved);

        return $filler;
    }

    private function anime(string ...$urls): TvAnime
    {
        $anime = new TvAnime();
        foreach ($urls as $url) {
            $anime->addSource($url);
        }

        return $anime;
    }

    public function testAvailableOnlyWhenSomeFillerRecognisesALink(): void
    {
        $this->assertTrue($this->availability(['plugin-a' => $this->filler(null), 'plugin-b' => $this->filler('1')])->isAvailable($this->anime('https://a.example/1')));
        $this->assertFalse($this->availability(['plugin-a' => $this->filler(null)])->isAvailable($this->anime('https://a.example/1')));
        $this->assertFalse($this->availability(['plugin-a' => $this->filler('1')])->isAvailable($this->anime()));
    }

    public function testAFillerThatThrowsIsSkippedInsteadOfBreakingTheCheck(): void
    {
        $this->assertTrue($this->availability(['plugin-a' => $this->filler(null, throws: true), 'plugin-b' => $this->filler('1')])->isAvailable($this->anime('https://a.example/1')));
        $this->assertFalse($this->availability(['plugin-a' => $this->filler(null, throws: true)])->isAvailable($this->anime('https://a.example/1')));
    }
}
