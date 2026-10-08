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

namespace App\Tests\Unit\Service\Import\V1;

use App\Service\Import\V1\V1ImportReportStore;
use App\Service\Import\V1\V1ImportResult;
use App\Tests\Support\TemporaryDirectories;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class V1ImportReportStoreTest extends TestCase
{
    use TemporaryDirectories;

    private string $path;

    protected function setUp(): void
    {
        $this->path = $this->createTemporaryDirectory('v1-report-').'/import-v1-report.json';
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testRoundTripsAResult(): void
    {
        $result = new V1ImportResult(animeCreated: 5, withStatusFromLabel: 2, withDefaultStatus: 3, genresUnmapped: 2, unmappedGenreNames: ['Яой', 'Hentai'], coversMissing: 4, skippedStorageNames: ['D:'], storagesUnavailable: 1);

        $this->store()->save($result);

        $this->assertEquals($result, $this->store()->load());
        $this->assertSame([], glob($this->path.'.*'), 'no temporary file is left behind');
    }

    public function testMissingFileIsNoReport(): void
    {
        $this->assertNull($this->store()->load());
    }

    public function testBrokenJsonIsNoReport(): void
    {
        file_put_contents($this->path, '{"animeCreated": 5');

        $this->assertNull($this->store()->load());
    }

    public function testNonObjectJsonIsNoReport(): void
    {
        file_put_contents($this->path, '"text"');

        $this->assertNull($this->store()->load());
    }

    public function testFieldsOfTheWrongTypeAreZeroedOrDropped(): void
    {
        file_put_contents($this->path, json_encode([
            'animeCreated' => 7,
            'sources' => '12',
            'studios' => -3,
            'labels' => 1.5,
            'coversMissing' => [1],
            'unmappedGenreNames' => ['ok', 5, null, ['x'], ''],
            'skippedStorageNames' => 'D:',
        ]));

        $result = $this->store()->load();

        $this->assertNotNull($result);
        $this->assertSame(7, $result->animeCreated);
        $this->assertSame(0, $result->sources);
        $this->assertSame(0, $result->studios);
        $this->assertSame(0, $result->labels);
        $this->assertSame(0, $result->coversMissing);
        $this->assertSame(['ok'], $result->unmappedGenreNames);
        $this->assertSame([], $result->skippedStorageNames);
    }

    public function testOverlongListsAndStringsAreCapped(): void
    {
        file_put_contents($this->path, json_encode([
            'animeCreated' => 1,
            'unmappedGenreNames' => [...array_map(static fn (int $i): string => "g$i", range(1, 500)), str_repeat('x', 201)],
        ]));

        $result = $this->store()->load();

        $this->assertNotNull($result);
        $this->assertCount(100, $result->unmappedGenreNames);
        $this->assertSame('g1', $result->unmappedGenreNames[0]);
    }

    public function testOverlongStringIsDropped(): void
    {
        file_put_contents($this->path, json_encode(['animeCreated' => 1, 'unmappedGenreNames' => [str_repeat('x', 201), 'ok']]));

        $this->assertSame(['ok'], $this->store()->load()?->unmappedGenreNames);
    }

    public function testOversizedFileIsNoReport(): void
    {
        file_put_contents($this->path, json_encode(['animeCreated' => 1, 'padding' => str_repeat('x', 300000)]));

        $this->assertNull($this->store()->load());
    }

    public function testReportWithNoCreatedEntriesIsNoReport(): void
    {
        file_put_contents($this->path, json_encode(['coversMissing' => 4]));

        $this->assertNull($this->store()->load());
    }

    public function testDismissRemovesTheFileAndToleratesItsAbsence(): void
    {
        $this->store()->save(new V1ImportResult(animeCreated: 1));
        $this->assertFileExists($this->path);

        $this->store()->dismiss();
        $this->store()->dismiss();

        $this->assertFileDoesNotExist($this->path);
        $this->assertNull($this->store()->load());
    }

    public function testFailedSaveDoesNotThrow(): void
    {
        $store = new V1ImportReportStore(\dirname($this->path).'/missing/import-v1-report.json', new NullLogger());

        $store->save(new V1ImportResult(animeCreated: 1));

        $this->assertNull($store->load());
    }

    private function store(): V1ImportReportStore
    {
        return new V1ImportReportStore($this->path, new NullLogger());
    }
}
