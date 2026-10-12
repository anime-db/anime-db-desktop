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

namespace App\Tests\Unit\Service\Notification;

use App\Service\AppSettingsProvider;
use App\Service\NearestBuiltInLocale;
use App\Service\Notification\NotificationCenter;
use App\Service\Notification\NotificationKindRegistry;
use App\Service\Notification\NotificationMergePolicy;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\NotificationSubjectLinker;
use App\Service\WsPublisher;
use App\Tests\Support\NotificationTestKit;
use App\Tests\Support\NotificationTestLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class NotificationCenterTest extends TestCase
{
    private Connection $connection;
    /** @var list<array{event: string, data: array<string, mixed>}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once \dirname(__DIR__, 4).'/migrations/Version20261009000000.php';
        $migration = new Version20261009000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        $this->published = [];
    }

    private function center(NotificationMergePolicy $policy = NotificationMergePolicy::CoalesceUnread, ?WsPublisher $ws = null, ?NotificationRenderer $renderer = null, ?NotificationTestLogger $logger = null): NotificationCenter
    {
        $registry = new NotificationKindRegistry([
            new NotificationTestKit($policy),
            new NotificationTestKit($policy, 'other_event'),
        ]);

        if ($ws === null) {
            $ws = $this->createStub(WsPublisher::class);
            $ws->method('publish')->willReturnCallback(function (string $event, mixed $data): void {
                $this->published[] = ['event' => $event, 'data' => $data];
            });
        }

        if ($renderer === null) {
            $translator = new Translator('en');
            $translator->addLoader('array', new ArrayLoader());
            foreach (NotificationTestKit::messages() as $locale => $catalogue) {
                $translator->addResource('array', $catalogue, $locale);
            }
            $settings = $this->createStub(AppSettingsProvider::class);
            $settings->method('getLocale')->willReturn('ru');
            $renderer = new NotificationRenderer($registry, $translator, $settings, new NearestBuiltInLocale(), $this->createStub(NotificationSubjectLinker::class), new NullLogger());
        }

        return new NotificationCenter($this->connection, $registry, $renderer, $ws, $logger ?? new NullLogger());
    }

    private function rows(string $where = '1'): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification WHERE '.$where);
    }

    public function testCoalesceUnreadMergesIntoOneRecordAndPublishesTheNewOccurrences(): void
    {
        $center = $this->center();

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));
        $this->connection->executeStatement('UPDATE notification SET occurred_at = 1');
        $center->record(NotificationTestKit::testEvent('A', 5, 'disk_space'));

        $this->assertSame(1, $this->rows());
        $row = $this->connection->fetchAssociative('SELECT * FROM notification');
        $this->assertIsArray($row);
        $this->assertSame(2, (int) $row['occurrences']);
        $this->assertSame(['name' => 'A', 'count' => 5, 'reason' => 'disk_space'], json_decode((string) $row['params'], true));
        $this->assertGreaterThan(1, (int) $row['occurred_at']);

        $this->assertCount(2, $this->published);
        $last = $this->published[1];
        $this->assertSame('notification.created', $last['event']);
        $this->assertSame([
            'id' => (int) $row['id'],
            'occurrences' => 2,
            'kind' => 'test_event',
            'severity' => 'warning',
            'title' => 'Тестовое событие: A',
            'body' => 'Элементов: 5, причина: нет места на диске',
            'url' => null,
            'unread_count' => 1,
        ], $last['data']);
    }

    public function testReadRecordIsNotMergedIntoAndANewOneIsCreated(): void
    {
        $center = $this->center();
        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));
        $this->connection->executeStatement('UPDATE notification SET read_at = '.time());

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(2, $this->rows());
        $this->assertSame(1, $this->rows('read_at IS NULL AND occurrences = 1'));
    }

    public function testAlwaysPolicyCreatesARecordPerEvent(): void
    {
        $center = $this->center(NotificationMergePolicy::Always);

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));
        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(2, $this->rows());
        $this->assertSame(2, $this->rows('dedup_key IS NULL'));
    }

    public function testOnceKeyFiresOnceEvenAfterRetentionDeletedTheRecord(): void
    {
        $center = $this->center(NotificationMergePolicy::Once);

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));
        $this->connection->executeStatement('UPDATE notification SET read_at = 1, occurred_at = 1');
        $center->record(NotificationTestKit::testEvent('B', 1, 'network'));

        // the retention triggered by the second record has removed the old read record
        $this->assertSame(1, $this->rows());
        $this->assertSame(0, $this->rows('occurred_at = 1'));
        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(1, $this->rows());
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification_once_key'));
        $this->assertCount(2, $this->published);
    }

    public function testRetentionDropsReadRecordsOlderThanThirtyDaysOnly(): void
    {
        $center = $this->center(NotificationMergePolicy::Always);
        $now = time();
        $insert = 'INSERT INTO notification (kind, severity, params, occurred_at, read_at, occurrences) VALUES (\'test_event\', \'info\', \'{}\', ?, ?, 1)';
        $this->connection->executeStatement($insert, [$now - 40 * 86400, $now - 31 * 86400]);
        $this->connection->executeStatement($insert, [$now - 40 * 86400, null]);
        $this->connection->executeStatement($insert, [$now - 40 * 86400, $now - 29 * 86400]);

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(3, $this->rows());
        $this->assertSame(1, $this->rows('read_at IS NULL AND occurred_at < '.($now - 30 * 86400)));
    }

    public function testRetentionCountsFromReadAtNotFromOccurredAt(): void
    {
        $center = $this->center(NotificationMergePolicy::Always);
        $now = time();
        $insert = 'INSERT INTO notification (kind, severity, params, occurred_at, read_at, occurrences) VALUES (\'test_event\', \'info\', \'{}\', ?, ?, 1)';
        // happened 40 days ago, read just now: kept
        $this->connection->executeStatement($insert, [$now - 40 * 86400, $now]);
        // read 31 days ago: dropped
        $this->connection->executeStatement($insert, [$now - 40 * 86400, $now - 31 * 86400]);

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(1, $this->rows('read_at = '.$now));
        $this->assertSame(0, $this->rows('read_at = '.($now - 31 * 86400)));
        $this->assertSame(2, $this->rows());
    }

    public function testCapEvictsReadRecordsFirstAndUnreadOnlyWhenUnreadAloneExceedIt(): void
    {
        $center = $this->center(NotificationMergePolicy::Always);
        $now = time();
        $insert = 'INSERT INTO notification (kind, severity, params, occurred_at, read_at, occurrences) VALUES (\'test_event\', \'info\', \'{}\', ?, ?, 1)';
        // 300 unread + 300 read, all fresh: 600 > 500
        for ($i = 0; $i < 300; ++$i) {
            $this->connection->executeStatement($insert, [$now - 1000 + $i, null]);
            $this->connection->executeStatement($insert, [$now - 1000 + $i, $now]);
        }

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(500, $this->rows());
        $this->assertSame(301, $this->rows('read_at IS NULL'));

        // now unread alone exceed the cap: 600 unread
        $this->connection->executeStatement('DELETE FROM notification');
        for ($i = 0; $i < 600; ++$i) {
            $this->connection->executeStatement($insert, [$now - 1000 + $i, null]);
        }
        $this->connection->executeStatement($insert, [$now - 2000, $now]);

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertSame(500, $this->rows());
        $this->assertSame(500, $this->rows('read_at IS NULL'));
    }

    public function testRecordNeverThrowsAndLogsWhenStorageFails(): void
    {
        $logger = NotificationTestKit::logger();
        $this->connection->executeStatement('DROP TABLE notification');

        $this->center(logger: $logger)->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertTrue($logger->has('error'));
        $this->assertSame([], $this->published);
    }

    public function testRecordNeverThrowsAndLogsWhenRenderingFails(): void
    {
        $logger = NotificationTestKit::logger();
        $renderer = $this->createStub(NotificationRenderer::class);
        $renderer->method('render')->willThrowException(new \RuntimeException('render'));

        $this->center(renderer: $renderer, logger: $logger)->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertTrue($logger->has('error'));
        $this->assertSame(1, $this->rows());
    }

    public function testRecordNeverThrowsAndLogsWhenThePublisherFails(): void
    {
        $logger = NotificationTestKit::logger();
        $ws = $this->createStub(WsPublisher::class);
        $ws->method('publish')->willThrowException(new \RuntimeException('busy'));

        $this->center(ws: $ws, logger: $logger)->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertTrue($logger->has('error'));
        $this->assertSame(1, $this->rows());
    }

    public function testUnknownKindIsLoggedAndNothingIsWritten(): void
    {
        $logger = NotificationTestKit::logger();
        $center = new NotificationCenter(
            $this->connection,
            new NotificationKindRegistry([]),
            $this->createStub(NotificationRenderer::class),
            $this->createStub(WsPublisher::class),
            $logger,
        );

        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));

        $this->assertTrue($logger->has('error'));
        $this->assertSame(0, $this->rows());
    }

    public function testTwoKindsWithTheSameRawKeyKeepSeparateRecords(): void
    {
        $center = $this->center();

        $center->record(NotificationTestKit::otherKindDraft('test_event', 'A', 'anime:42'));
        $center->record(NotificationTestKit::otherKindDraft('other_event', 'B', 'anime:42'));

        $this->assertSame(2, $this->rows());
        $this->assertSame(1, $this->rows("kind = 'test_event' AND occurrences = 1"));
        $this->assertSame(1, $this->rows("kind = 'other_event' AND occurrences = 1"));
    }

    public function testOnceKeyOfOneKindDoesNotSilenceAnotherKind(): void
    {
        $center = $this->center(NotificationMergePolicy::Once);

        $center->record(NotificationTestKit::otherKindDraft('test_event', 'A', 'anime:42'));
        $center->record(NotificationTestKit::otherKindDraft('other_event', 'B', 'anime:42'));
        $center->record(NotificationTestKit::otherKindDraft('other_event', 'B', 'anime:42'));

        $this->assertSame(2, $this->rows());
    }

    public function testRecordInsideARolledBackTransactionIsLostButTheEventIsAlreadyPublished(): void
    {
        $center = $this->center();

        $this->connection->beginTransaction();
        $center->record(NotificationTestKit::testEvent('A', 1, 'network'));
        $this->connection->rollBack();

        // the documented reason not to call record() from inside a transaction
        $this->assertSame(0, $this->rows());
        $this->assertCount(1, $this->published);
    }
}
