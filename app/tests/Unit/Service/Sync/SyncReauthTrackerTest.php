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

namespace App\Tests\Unit\Service\Sync;

use App\Service\Notification\Kind\SyncReauthNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Notification\NotificationDraft;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Sync\SyncReauthTracker;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261010000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SyncReauthTrackerTest extends TestCase
{
    use TemporaryDirectories;

    private Connection $connection;
    /** @var list<NotificationDraft> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once \dirname(__DIR__, 4).'/migrations/Version20261010000000.php';
        $migration = new Version20261010000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        $this->recorded = [];
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    private function tracker(?\Throwable $recordFailure = null, ?Connection $connection = null): SyncReauthTracker
    {
        $center = $this->createStub(NotificationCenter::class);
        $center->method('record')->willReturnCallback(function (NotificationDraft $draft) use ($recordFailure): void {
            $this->recorded[] = $draft;
            if ($recordFailure !== null) {
                throw $recordFailure;
            }
        });
        $dir = $this->createTemporaryDirectory('reauth');

        return new SyncReauthTracker(
            $connection ?? $this->connection,
            $center,
            new InstalledPluginsRegistry($dir, new PluginsConfigStore($dir.'/plugins.json'), new NullLogger()),
            new NullLogger(),
        );
    }

    public function testTwoReauthFailuresInARowGiveOneNotificationAndASecondOneAfterTheFlagIsCleared(): void
    {
        $tracker = $this->tracker();

        $tracker->onReauthRequired('animedb-mal');
        $tracker->onReauthRequired('animedb-mal');
        $this->assertCount(1, $this->recorded);

        $tracker->onSyncSucceeded('animedb-mal');
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT needs_reauth FROM plugin_sync_state WHERE plugin_id = 'animedb-mal'"));

        $tracker->onReauthRequired('animedb-mal');
        $this->assertCount(2, $this->recorded);
    }

    public function testTheFlagIsPerPlugin(): void
    {
        $tracker = $this->tracker();

        $tracker->onReauthRequired('animedb-mal');
        $tracker->onReauthRequired('animedb-shikimori');

        $this->assertCount(2, $this->recorded);
    }

    public function testTheDraftIsBuiltByTheKindFactoryWithTheFallbackNameForAnUnknownPlugin(): void
    {
        $this->tracker()->onReauthRequired('animedb-mal');

        $expected = SyncReauthNotificationKind::draft('animedb-mal', 'animedb-mal');
        $this->assertEquals($expected, $this->recorded[0]);
        $this->assertSame(['type' => 'plugin', 'id' => 'animedb-mal'], $this->recorded[0]->subject());
    }

    public function testSuccessWithoutARowIsHarmless(): void
    {
        $this->tracker()->onSyncSucceeded('animedb-mal');

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM plugin_sync_state'));
    }

    public function testAFailingNotificationDoesNotEscape(): void
    {
        $this->tracker(new \RuntimeException('boom'))->onReauthRequired('animedb-mal');

        $this->assertCount(1, $this->recorded);
    }

    public function testAFailingFlagWriteDoesNotEscapeAndRecordsNothing(): void
    {
        $tracker = $this->tracker(connection: DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        $tracker->onReauthRequired('animedb-mal');
        $tracker->onSyncSucceeded('animedb-mal');

        $this->assertSame([], $this->recorded);
    }
}
