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

namespace App\Tests\Unit\Service\Download;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadActionOutcome;
use App\Service\Download\DownloadActionService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class DownloadActionServiceTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $repository;
    private DownloadActionService $service;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new DownloadRepository($this->entityManager);
        $this->service = new DownloadActionService($this->entityManager);
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Anime A')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testRetrySucceedsForAFailedRowWithARetryableReasonAndPersistsTheReset(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed('disk_space');
        $download->incrementMoveAttempts();
        $this->repository->save($download);

        $outcome = $this->service->retry($download, $download->getVersion(), $download->getStatus());

        $this->assertSame(DownloadActionOutcome::Success, $outcome);

        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(DownloadStatus::Pending, $reloaded->getStatus());
        $this->assertNull($reloaded->getFailureReason());
        $this->assertSame(0, $reloaded->getMoveAttempts());
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function moveAttemptsAfterRetryProvider(): iterable
    {
        yield 'move_failed' => ['move_failed', 1];
        yield 'name_conflict' => ['name_conflict', 0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moveAttemptsAfterRetryProvider')]
    public function testRetryPersistsMoveAttemptsDependingOnTheFailureReason(string $reason, int $expected): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed($reason);
        $download->incrementMoveAttempts();
        $download->incrementMoveAttempts();
        $this->repository->save($download);

        $outcome = $this->service->retry($download, $download->getVersion(), $download->getStatus());

        $this->assertSame(DownloadActionOutcome::Success, $outcome);
        $stored = $this->entityManager->getConnection()->fetchOne('SELECT move_attempts FROM downloads WHERE id = ?', [$download->id]);
        $this->assertSame($expected, (int) $stored);
    }

    public function testRetryIsRefusedForANonRetryableReasonAndNeverTouchesTheDatabase(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed('storage_conflict');
        $this->repository->save($download);

        $outcome = $this->service->retry($download, $download->getVersion(), $download->getStatus());

        $this->assertSame(DownloadActionOutcome::Refused, $outcome);

        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Failed, $reloaded?->getStatus());
        $this->assertSame('storage_conflict', $reloaded->getFailureReason());
    }

    /**
     * Guards the optimistic-lock race with DownloadCompletionPoller (issue #856): a version that
     * no longer matches must leave the row exactly as the concurrent writer left it, reported back
     * as a conflict rather than silently retried on top of stale data. $expectedVersion/$expectedStatus
     * stand in for a retry form's hidden fields, captured here before the concurrent write — exactly
     * what DownloadActionController would have read off a request that raced the poller.
     */
    public function testRetryReportsConflictWhenTheRowVersionChangedConcurrentlyAndLeavesItUntouched(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed('disk_space');
        $this->repository->save($download);
        $expectedVersion = $download->getVersion();
        $expectedStatus = $download->getStatus();

        // Simulates a concurrent writer (the poller) touching the row after it was read here.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET version = version + 1 WHERE id = ?',
            [$download->id],
        );

        $outcome = $this->service->retry($download, $expectedVersion, $expectedStatus);

        $this->assertSame(DownloadActionOutcome::Conflict, $outcome);

        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Failed, $reloaded?->getStatus());
        $this->assertSame('disk_space', $reloaded->getFailureReason());
    }

    /**
     * @return iterable<string, array{0: DownloadStatus}>
     */
    public static function deletableStatusProvider(): iterable
    {
        yield 'Pending' => [DownloadStatus::Pending];
        yield 'Failed' => [DownloadStatus::Failed];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deletableStatusProvider')]
    public function testDeleteRemovesTheRowForPendingOrFailed(DownloadStatus $status): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        if ($status === DownloadStatus::Failed) {
            $download->markFailed();
        }
        $this->repository->save($download);

        $outcome = $this->service->delete($download, $download->getVersion(), $download->getStatus());

        $this->assertSame(DownloadActionOutcome::Success, $outcome);
        $this->entityManager->clear();
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    public function testDeleteIsRefusedForACompletedRowAndNeverTouchesTheDatabase(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markCompleted();
        $this->repository->save($download);

        $outcome = $this->service->delete($download, $download->getVersion(), $download->getStatus());

        $this->assertSame(DownloadActionOutcome::Refused, $outcome);
        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    /**
     * Pins issue #856's acceptance criterion: a row the poller just completed concurrently must
     * survive the delete untouched, reported back as a conflict rather than removed using stale
     * (id, version, status) data. $expectedVersion/$expectedStatus are captured before the
     * concurrent write, standing in for a delete form's hidden fields — what the human actually
     * saw, not whatever the row holds by the time the request is handled.
     */
    public function testDeleteReportsConflictWhenTheRowChangedStatusConcurrentlyAndLeavesItInPlace(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);
        $expectedVersion = $download->getVersion();
        $expectedStatus = $download->getStatus();

        // Simulates DownloadCompletionPoller completing the row from a separate process after this
        // request already loaded $download as still Pending.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET status = ?, version = version + 1 WHERE id = ?',
            [DownloadStatus::Completed->value, $download->id],
        );

        $outcome = $this->service->delete($download, $expectedVersion, $expectedStatus);

        $this->assertSame(DownloadActionOutcome::Conflict, $outcome);
        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Completed, $reloaded?->getStatus());
    }

    /**
     * $expectedStatus alone must gate the conditional DELETE, not just $expectedVersion: a row the
     * poller failed and then (e.g. via a retry started from a different tab) moved on from since
     * the page was rendered could, in principle, land back on the same version number a buggy
     * check might only compare loosely — pinning that the WHERE clause's `status = ?` is what
     * actually catches a mismatched $expectedStatus.
     */
    public function testDeleteReportsConflictWhenExpectedStatusDoesNotMatchTheRowEvenWithItsCurrentVersion(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $download->markFailed();
        $this->repository->save($download);

        $outcome = $this->service->delete($download, $download->getVersion(), DownloadStatus::Pending);

        $this->assertSame(DownloadActionOutcome::Conflict, $outcome);
        $this->entityManager->clear();
        $reloaded = $this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertSame(DownloadStatus::Failed, $reloaded?->getStatus());
    }
}
