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
use App\Service\Notification\Kind\DownloadCompletedNotificationKind;
use App\Service\Notification\Kind\DownloadFailedNotificationKind;
use App\Service\Notification\Kind\ScanAttentionNotificationKind;
use App\Service\Notification\Kind\ScanFailedNotificationKind;
use App\Service\Notification\Kind\SyncReviewNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Notification\NotificationKindRegistry;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\NotificationSubjectLinker;
use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/** The four production kinds of the scan and download sources (issue #1026). */
final class NotificationSourcesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once \dirname(__DIR__, 3).'/../migrations/Version20261009000000.php';
        $migration = new Version20261009000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function center(): NotificationCenter
    {
        $registry = new NotificationKindRegistry([new ScanFailedNotificationKind(), new ScanAttentionNotificationKind(), new DownloadCompletedNotificationKind(), new DownloadFailedNotificationKind(), new SyncReviewNotificationKind()]);
        $renderer = new NotificationRenderer(
            $registry,
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(AppSettingsProvider::class),
            new NearestBuiltInLocale(),
            $this->createStub(NotificationSubjectLinker::class),
            new NullLogger(),
        );

        return new NotificationCenter($this->connection, $registry, $renderer, $this->createStub(WsPublisher::class), new NullLogger());
    }

    public function testRepeatedSyncReviewRunsShareOneUnreadRecordWithTheLatestCount(): void
    {
        $center = $this->center();

        $center->record(SyncReviewNotificationKind::draft(3));
        $center->record(SyncReviewNotificationKind::draft(7));

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM notification');
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['occurrences']);
        $this->assertSame('warning', $rows[0]['severity']);
        $this->assertSame(['count' => 7], json_decode((string) $rows[0]['params'], true));
        $this->assertNull($rows[0]['subject_type']);
    }

    public function testSyncReviewFixtureIsExactlyTheRowTheFactoryWrites(): void
    {
        $this->center()->record(SyncReviewNotificationKind::draft(3));

        $row = $this->connection->fetchAssociative('SELECT kind, severity, params, subject_type, subject_id FROM notification');
        $this->assertIsArray($row);
        $actual = [
            'kind' => $row['kind'],
            'severity' => $row['severity'],
            'params' => json_decode((string) $row['params'], true),
            'subject' => $row['subject_type'] === null ? null : ['type' => $row['subject_type'], 'id' => $row['subject_id']],
        ];

        $this->assertSame(json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/Fixtures/Notifications/sync_review.v1.json'), true), $actual);
    }

    public function testRepeatedScanAttentionOfAStorageUpdatesTheUnreadRecord(): void
    {
        $center = $this->center();

        $center->record(ScanAttentionNotificationKind::draft('Main', 1, 0, 0, 0, 0, 3, 10));
        $center->record(ScanAttentionNotificationKind::draft('Main', 0, 2, 0, 0, 4, 3, 11));
        $center->record(ScanAttentionNotificationKind::draft('Other', 1, 0, 0, 0, 0, 4, 12));

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM notification ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[0]['occurrences']);
        $this->assertSame(
            ['name' => 'Main', 'needs_confirmation' => 0, 'needs_manual_entry' => 2, 'files_missing' => 0, 'conflict' => 0, 'error' => 4],
            json_decode((string) $rows[0]['params'], true),
        );
        $this->assertSame('3:11', $rows[0]['subject_id']);
        $this->assertSame('warning', $rows[0]['severity']);
        $this->assertSame(1, (int) $rows[1]['occurrences']);
    }

    public function testFailuresAndCompletionsAreNeverMerged(): void
    {
        $center = $this->center();

        $center->record(ScanFailedNotificationKind::draft('Main', 'exception', 3, 10));
        $center->record(ScanFailedNotificationKind::draft('Main', 'exception', 3, 11));
        $center->record(DownloadCompletedNotificationKind::draft('Trigun', 7));
        $center->record(DownloadCompletedNotificationKind::draft('Trigun', 7));
        $center->record(DownloadFailedNotificationKind::draft('Trigun', 7, null));
        $center->record(DownloadFailedNotificationKind::draft('Trigun', 7, null));

        $this->assertSame(6, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification'));
        $this->assertSame(
            ['download_completed' => 'success', 'download_failed' => 'error', 'scan_failed' => 'error'],
            $this->connection->fetchAllKeyValue('SELECT DISTINCT kind, severity FROM notification ORDER BY kind'),
        );
    }

    public function testScanDraftsWithoutAJournalRunHaveNoSubject(): void
    {
        $this->assertNull(ScanFailedNotificationKind::draft('Main', 'marker_conflict')->subject());
        $this->assertNull(ScanAttentionNotificationKind::draft('Main', 1, 2, 3, 4, 5, 3)->subject());
        $this->assertSame(['type' => 'storage_scan_run', 'id' => '3:12'], ScanAttentionNotificationKind::draft('Main', 1, 2, 3, 4, 5, 3, 12)->subject());
    }

    public function testFactoriesRejectCodesOutsideTheClosedSets(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DownloadFailedNotificationKind::draft('Trigun', 7, 'brand_new_reason');
    }

    public function testScanFailedRejectsAnUnknownReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScanFailedNotificationKind::draft('Main', 'brand_new_reason');
    }

    /** @return iterable<string, array<string>> */
    public static function locales(): iterable
    {
        yield 'ru' => ['ru'];
        yield 'en' => ['en'];
    }

    #[DataProvider('locales')]
    public function testTextsOfTheFourKindsExistWithTheDeclaredPlaceholdersOnly(string $locale): void
    {
        $catalogue = Yaml::parseFile(\dirname(__DIR__, 4).'/translations/messages.'.$locale.'.yaml')['notification'];
        $allowed = [
            'scan_failed' => ['name', 'reason'],
            'scan_attention' => ['name', 'needs_confirmation', 'needs_manual_entry', 'files_missing', 'conflict', 'error'],
            'download_completed' => ['name'],
            'download_failed' => ['name', 'reason'],
        ];

        foreach ($allowed as $kind => $placeholders) {
            foreach (['title', 'body'] as $part) {
                $this->assertArrayHasKey($part, $catalogue[$kind]);
                preg_match_all('/%([a-z_]+)%/', $catalogue[$kind][$part], $m);
                $this->assertSame([], array_diff($m[1], $placeholders), $kind.'.'.$part);
            }
        }
        $this->assertEqualsCanonicalizing(['marker_conflict', 'exception'], array_keys($catalogue['scan_failed']['reason']));
        $this->assertEqualsCanonicalizing(['none', ...DownloadFailedNotificationKind::REASONS], array_keys($catalogue['download_failed']['reason']));
    }
}
