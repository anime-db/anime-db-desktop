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

namespace App\Tests\Unit\Service\Migration;

use App\Service\Migration\MigrationDataIntegrityChecker;
use App\Service\Plugin\PhpCliCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Proves the verify*() methods actually detect the failure modes
 * {@see \App\Command\MigrationDataIntegrityCheckCommand} exists to catch, rather than trusting
 * that "it was checked manually while implementing the command" stays true forever. Each test
 * starts from a template database — built once in setUpBeforeClass() by staging the fixture
 * through the real migration chain exactly as the command does, since insertEarlyFixture() targets
 * the `anime_name` shape from before its rebuild (Version20260923000000 replaces its `type` column
 * with `locale`/`role`) and cannot be called after the chain has already reached its end — corrupts
 * one specific thing by hand, and asserts the corresponding verify*() call reports it. That proves
 * a weakened check (e.g. a verify*() that always returns []) would fail these tests.
 */
final class MigrationDataIntegrityCheckerTest extends TestCase
{
    private const APP_DIR = __DIR__.'/../../../..';

    /** Mirrors MigrationDataIntegrityCheckCommand::AFTER_EARLY_TABLES_VERSION. */
    private const AFTER_EARLY_TABLES_VERSION = 'DoctrineMigrations\Version20260801000002';

    /** Mirrors MigrationDataIntegrityCheckCommand::AFTER_DOWNLOADS_TABLE_VERSION. */
    private const AFTER_DOWNLOADS_TABLE_VERSION = 'DoctrineMigrations\Version20260809000000';

    /** Mirrors MigrationDataIntegrityCheckCommand::AFTER_LATE_TABLES_VERSION. */
    private const AFTER_LATE_TABLES_VERSION = 'DoctrineMigrations\Version20260812000001';

    private static string $templateDir;

    /** @var array{animeId: int, nameIds: list<int>, names: array<int, string>, counts: array<string, int>} */
    private static array $templateFixture;

    private string $workDir;
    private MigrationDataIntegrityChecker $checker;

    public static function setUpBeforeClass(): void
    {
        self::$templateDir = sys_get_temp_dir().'/animedb_migrations_checker_template_'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir(self::$templateDir);

        $checker = new MigrationDataIntegrityChecker();
        $dbPath = self::$templateDir.'/template.db';

        self::migrate($dbPath, self::AFTER_EARLY_TABLES_VERSION);
        $pdo = $checker->connect($dbPath);
        $fixture = $checker->insertEarlyFixture($pdo);
        unset($pdo);

        self::migrate($dbPath, self::AFTER_DOWNLOADS_TABLE_VERSION);
        $pdo = $checker->connect($dbPath);
        $fixture['counts'] = [...$fixture['counts'], ...$checker->insertMidFixture($pdo, $fixture['animeId'])];
        unset($pdo);

        self::migrate($dbPath, self::AFTER_LATE_TABLES_VERSION);
        $pdo = $checker->connect($dbPath);
        $fixture['counts'] = [...$fixture['counts'], ...$checker->insertLateFixture($pdo, $fixture['animeId'])];
        unset($pdo);

        self::migrate($dbPath, null);

        self::$templateFixture = $fixture;
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::$templateDir);
    }

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/animedb_migrations_checker_test_'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->workDir);
        $this->checker = new MigrationDataIntegrityChecker();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    public function testVerifyCountsReportsARowLostFromAChildTable(): void
    {
        $pdo = $this->fromTemplate();
        $counts = self::$templateFixture['counts'];

        $this->assertSame([], $this->checker->verifyCounts($pdo, $counts), 'fixture must be clean before corrupting it');

        $pdo->exec('DELETE FROM anime_genres');

        $violations = $this->checker->verifyCounts($pdo, $counts);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('anime_genres: expected 1 row(s), found 0', implode("\n", $violations));
    }

    public function testVerifyIdsReportsARenumberedAnimeName(): void
    {
        $pdo = $this->fromTemplate();
        $nameIds = self::$templateFixture['nameIds'];

        $this->assertSame([], $this->checker->verifyIds($pdo, 'anime_name', $nameIds));

        $pdo->exec('UPDATE anime_name SET id = 1 WHERE id = 5');

        $violations = $this->checker->verifyIds($pdo, 'anime_name', $nameIds);
        $this->assertCount(1, $violations);
        $violation = implode("\n", $violations);
        $this->assertStringContainsString('expected ids [5, 9]', $violation);
        $this->assertStringContainsString('found [1, 9]', $violation);
    }

    public function testVerifyFtsSyncReportsADesyncedRow(): void
    {
        $pdo = $this->fromTemplate();
        $names = self::$templateFixture['names'];
        $animeId = self::$templateFixture['animeId'];

        $this->assertSame([], $this->checker->verifyFtsSync($pdo, $names, $animeId));

        $pdo->exec('DELETE FROM anime_fts WHERE rowid = -5');

        $violations = $this->checker->verifyFtsSync($pdo, $names, $animeId);
        $this->assertNotSame([], $violations);
        $violation = implode("\n", $violations);
        $this->assertStringContainsString('rowid=-5', $violation);
        $this->assertStringContainsString('no row', $violation);
    }

    public function testVerifyCascadeDeleteReportsAMissingCascade(): void
    {
        $pdo = $this->fromTemplate();
        $animeId = self::$templateFixture['animeId'];

        $pdo->exec('PRAGMA foreign_keys = OFF');

        $violations = $this->checker->verifyCascadeDelete($pdo, $animeId);
        $this->assertNotSame([], $violations);
        $this->assertStringContainsString('anime_genres: 1 row(s)', implode("\n", $violations));
    }

    /**
     * Copies the shared template database into this test's own work dir, so each test corrupts
     * an independent file rather than the one other tests (or a later run of the same test) rely
     * on.
     */
    private function fromTemplate(): \PDO
    {
        $dbPath = $this->workDir.'/data.db';
        (new Filesystem())->copy(self::$templateDir.'/template.db', $dbPath);

        return $this->checker->connect($dbPath);
    }

    private static function migrate(string $dbPath, ?string $version): void
    {
        $arguments = ['doctrine:migrations:migrate', '--no-interaction'];
        if ($version !== null) {
            $arguments[] = $version;
        }

        $migrate = new Process(
            PhpCliCommand::forScript(\PHP_BINARY, self::APP_DIR.'/bin/console', ...$arguments),
            self::APP_DIR,
            [
                'DATABASE_URL' => 'sqlite:///'.$dbPath,
                'SYMFONY_DOTENV_VARS' => false,
            ],
        );
        $migrate->setTimeout(300);
        $migrate->run();
        if (!$migrate->isSuccessful()) {
            throw new \RuntimeException('Migrations failed: '.$migrate->getOutput().$migrate->getErrorOutput());
        }
    }
}
