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

namespace App\Tests\Unit\Controller;

use App\Controller\StorageScanLogController;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Storage\Scan\ScanItemResolver;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
use App\Tests\Support\RunsMigrations;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class StorageScanLogControllerTest extends TestCase
{
    use RunsMigrations;

    private ScanRunJournal $journal;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->runMigrationFile($connection, \dirname(__DIR__, 3).'/migrations/Version20261008000000.php');
        $clock = new MockClock(new \DateTimeImmutable('@1000'));
        $jobLock = new JobLockService(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $this->createStub(ProcessLivenessChecker::class),
            $clock,
        );
        $this->journal = new ScanRunJournal($connection, $jobLock, $clock);
    }

    private function storage(int $id): Storage
    {
        $storage = new Storage('Main', '/anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    /** @param array<int, string> $held */
    private function controller(array $held = [], ?Environment $twig = null): StorageScanLogController
    {
        $animes = $this->createStub(AnimeRepository::class);
        $animes->method('findStoragePathsByStorageId')->willReturn($held);

        return new StorageScanLogController($this->journal, new ScanItemResolver($animes), $twig ?? $this->createStub(Environment::class));
    }

    public function testItemsCarryTheComputedResolvedStateAndTheLatestFlag(): void
    {
        $runId = $this->journal->start(1);
        $this->journal->done($runId, [
            ['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun'],
            ['type' => 'NeedsManualEntry', 'storage_path' => 'Monster'],
            ['type' => 'Error', 'storage_path' => 'Broken'],
        ]);

        $response = $this->controller([5 => 'Trigun'])->items($this->storage(1), $runId);
        $body = json_decode((string) $response->getContent(), true);

        $this->assertTrue($body['latest']);
        $this->assertSame(['id' => $runId, 'status' => 'done'], $body['run']);
        $this->assertSame([true, false, null], array_column($body['items'], 'resolved'));
        $this->assertSame([1, 1, 1], array_column($body['items'], 'v'));
    }

    public function testOnlyTheNewestRunIsLatest(): void
    {
        $old = $this->journal->start(1);
        $this->journal->done($old, []);
        $new = $this->journal->start(1);
        $this->journal->done($new, []);

        $this->assertFalse(json_decode((string) $this->controller()->items($this->storage(1), $old)->getContent(), true)['latest']);
        $this->assertTrue(json_decode((string) $this->controller()->items($this->storage(1), $new)->getContent(), true)['latest']);
    }

    public function testAFailedRunDoesNotTakeActionabilityFromTheLastDoneRun(): void
    {
        $done = $this->journal->start(1);
        $this->journal->done($done, [['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun']]);
        $failed = $this->journal->start(1);
        $this->journal->fail($failed, ScanRunStatus::Failed, 'disk is gone');

        $this->assertTrue(json_decode((string) $this->controller()->items($this->storage(1), $done)->getContent(), true)['latest']);
        $this->assertFalse(json_decode((string) $this->controller()->items($this->storage(1), $failed)->getContent(), true)['latest']);
    }

    public function testARunOfAnotherStorageIsNotFoundOnTheItemsEndpoint(): void
    {
        $runId = $this->journal->start(1);

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->items(self::storageWithId(2), $runId);
    }

    public function testARunOfAnotherStorageIsNotFoundOnTheRunPage(): void
    {
        $runId = $this->journal->start(1);

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->show(self::storageWithId(2), $runId);
    }

    public function testAnUnknownRunIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->items($this->storage(1), 999);
    }

    public function testIndexRendersTheRecentRunsOfTheStorage(): void
    {
        $this->journal->done($this->journal->start(1), []);
        $this->journal->start(2);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_log.html.twig', $this->callback(
                static fn (array $params): bool => \count($params['runs']) === 1 && $params['runs'][0]->storageId === 1,
            ))
            ->willReturn('<html></html>');

        $this->assertSame(200, $this->controller(twig: $twig)->index($this->storage(1))->getStatusCode());
    }

    private static function storageWithId(int $id): Storage
    {
        $storage = new Storage('Other', '/other', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }
}
