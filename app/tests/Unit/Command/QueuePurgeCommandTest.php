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

namespace App\Tests\Unit\Command;

use App\Command\QueuePurgeCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class QueuePurgeCommandTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;

    protected function setUp(): void
    {
        $stub = tempnam(sys_get_temp_dir(), 'animedb_queue_purge_');
        $this->dbPath = $stub.'.db';
        unlink($stub);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $this->connection->executeStatement('CREATE TABLE messenger_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            body TEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE ws_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT NOT NULL,
            data TEXT
        )');
        $this->connection->executeStatement('CREATE TABLE job_locks (
            job_key VARCHAR(255) PRIMARY KEY NOT NULL,
            pid INTEGER NOT NULL,
            heartbeat_at INTEGER NOT NULL,
            started_at INTEGER NOT NULL
        )');
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    public function testDeletesAllPendingMessagesAndPrintsTheTranslatedCount(): void
    {
        $this->connection->insert('messenger_messages', ['body' => 'a', 'queue_name' => 'async']);
        $this->connection->insert('messenger_messages', ['body' => 'b', 'queue_name' => 'async']);

        $tester = new CommandTester(new QueuePurgeCommand($this->connection, $this->createTranslator()));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Queue purged (deleted: 2)', $tester->getDisplay());
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testSucceedsWithoutDeletingAnythingWhenTheQueueIsAlreadyEmpty(): void
    {
        $tester = new CommandTester(new QueuePurgeCommand($this->connection, $this->createTranslator()));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('deleted: 0', $tester->getDisplay());
    }

    public function testLeavesWsEventsAndJobLocksIntact(): void
    {
        $this->connection->insert('messenger_messages', ['body' => 'a', 'queue_name' => 'async']);
        $this->connection->insert('ws_events', ['event' => 'anime.updated', 'data' => '{}']);
        $this->connection->insert('job_locks', [
            'job_key' => 'scan:storage:1',
            'pid' => 123,
            'heartbeat_at' => 1000,
            'started_at' => 1000,
        ]);

        $tester = new CommandTester(new QueuePurgeCommand($this->connection, $this->createTranslator()));
        $tester->execute([]);

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ws_events'));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM job_locks'));

        // The connection (and file) stay usable for further reads/writes after the purge.
        $this->connection->insert('ws_events', ['event' => 'anime.deleted', 'data' => '{}']);
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ws_events'));
    }

    private function createTranslator(): Translator
    {
        $translationsDir = \dirname(__DIR__, 3).'/translations';

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $translationsDir.'/messages.en.yaml', 'en');

        return $translator;
    }
}
