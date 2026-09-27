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

namespace App\Command;

use App\Service\Migration\MigrationDataIntegrityChecker;
use App\Service\Plugin\PhpCliCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Applies every migration to a database that already holds data, and checks that the data is
 * still there afterwards. This complements `app:schema:check` (see SchemaCheckCommand), which
 * builds its database from scratch and can therefore never observe data loss: its snapshot of
 * `sqlite_master` is identical whether or not rows survived the migrations that built it.
 *
 * There is data to lose: SQLite has no `DROP/ALTER COLUMN` before 3.35 (not guaranteed in
 * FrankenPHP's bundled libsqlite3), so any migration that changes a referenced table's shape does
 * a full rebuild (`CREATE ...__new` -> `INSERT ... SELECT` -> `DROP TABLE` -> `RENAME`). Under
 * "PRAGMA foreign_keys = ON" (set on every real connection by the EnableForeignKeys middleware,
 * not by this throwaway connection, see MigrationDataIntegrityChecker::connect()), `DROP TABLE`
 * performs an implicit DELETE of every row first, which fires `ON DELETE CASCADE` on any table
 * referencing it — silently wiping children unless the migration toggles the pragma off for the
 * rebuild (see Version20260801000003). Separately, `anime_fts` addresses `anime_name` rows as
 * `rowid = -anime_name.id` (Version20260713000000); `DROP TABLE` fires no triggers, so a rebuild
 * that drops `id` from its `INSERT ... SELECT` re-numbers the surviving rows via AUTOINCREMENT and
 * strands the old `anime_fts` rows pointing at ids that no longer exist (Version20260923000000).
 *
 * The fixture is inserted directly via SQL, not through application code, so this check does not
 * depend on business logic and only exercises the schema itself.
 *
 * Tables cannot all be seeded at once: at the start of the chain none of them exist yet, and the
 * `anime`-rebuild migrations that run before any fixture exists (Version20260709000000,
 * Version20260710000000, Version20260712000002 — all before AFTER_EARLY_TABLES_VERSION) are
 * exercised only as schema-only smoke tests by this command; they have no data to lose at that
 * point, and are instead covered by their own migration tests
 * (see tests/Unit/Migrations). The fixture is instead inserted in three batches, each right after
 * the last migration that creates a table it needs, so every later up() rebuild that touches a
 * referenced table runs over real data: EARLY_FIXTURE_TABLES once the `anime` rebuild
 * (Version20260801000003) still lies ahead, MID_FIXTURE_TABLES (`downloads`) right after
 * Version20260809000000 creates it, and LATE_FIXTURE_TABLES (`anime_sync_state`) once
 * Version20260812000001 creates it, with only the `anime_name` rebuild (Version20260923000000)
 * still ahead.
 */
#[AsCommand(name: 'app:migrations:check-data-integrity', description: 'Run every migration over a populated database and check that no data was lost or desynced')]
final class MigrationDataIntegrityCheckCommand extends Command
{
    /** Last migration before Version20260801000003, the `anime` table rebuild. */
    private const AFTER_EARLY_TABLES_VERSION = 'DoctrineMigrations\Version20260801000002';

    /** The migration that creates `downloads`. */
    private const AFTER_DOWNLOADS_TABLE_VERSION = 'DoctrineMigrations\Version20260809000000';

    /** Last migration before Version20260923000000, the `anime_name` table rebuild. */
    private const AFTER_LATE_TABLES_VERSION = 'DoctrineMigrations\Version20260812000001';

    private readonly MigrationDataIntegrityChecker $checker;

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
        $this->checker = new MigrationDataIntegrityChecker();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem();
        $workDir = sys_get_temp_dir().'/animedb_migrations_data_check_'.bin2hex(random_bytes(6));
        $filesystem->mkdir($workDir);

        try {
            $dbPath = $workDir.'/data.db';
            $violations = [];

            $exitCode = $this->migrate($dbPath, self::AFTER_EARLY_TABLES_VERSION, $io);
            if ($exitCode !== null) {
                return $exitCode;
            }

            $pdo = $this->checker->connect($dbPath);
            $fixture = $this->checker->insertEarlyFixture($pdo);
            unset($pdo);

            $exitCode = $this->migrate($dbPath, self::AFTER_DOWNLOADS_TABLE_VERSION, $io);
            if ($exitCode !== null) {
                return $exitCode;
            }

            $pdo = $this->checker->connect($dbPath);
            $violations = [...$violations, ...$this->checker->verifyCounts($pdo, $fixture['counts'])];
            $fixture['counts'] = [...$fixture['counts'], ...$this->checker->insertMidFixture($pdo, $fixture['animeId'])];
            unset($pdo);

            $exitCode = $this->migrate($dbPath, self::AFTER_LATE_TABLES_VERSION, $io);
            if ($exitCode !== null) {
                return $exitCode;
            }

            $pdo = $this->checker->connect($dbPath);
            $violations = [...$violations, ...$this->checker->verifyCounts($pdo, $fixture['counts'])];
            $violations = [...$violations, ...$this->checker->verifyIds($pdo, 'anime_name', $fixture['nameIds'])];
            $fixture['counts'] = [...$fixture['counts'], ...$this->checker->insertLateFixture($pdo, $fixture['animeId'])];
            unset($pdo);

            $exitCode = $this->migrate($dbPath, null, $io);
            if ($exitCode !== null) {
                return $exitCode;
            }

            $pdo = $this->checker->connect($dbPath);
            $violations = [...$violations, ...$this->checker->verifyCounts($pdo, $fixture['counts'])];
            $violations = [...$violations, ...$this->checker->verifyIds($pdo, 'anime_name', $fixture['nameIds'])];
            $violations = [...$violations, ...$this->checker->verifyFtsSync($pdo, $fixture['names'], $fixture['animeId'])];
            $violations = [...$violations, ...$this->checker->verifyCascadeDelete($pdo, $fixture['animeId'])];
        } finally {
            $filesystem->remove($workDir);
        }

        if ($violations !== []) {
            $io->error('Data was lost or desynced while running migrations over a populated database:');
            $io->listing($violations);

            return Command::FAILURE;
        }

        $io->success('Every fixture row survived the full migration chain intact.');

        return Command::SUCCESS;
    }

    /**
     * Runs `doctrine:migrations:migrate` up to $version (or to the latest one, if null) in a
     * fresh subprocess, so it goes through the real console entry point rather than a hand-rolled
     * re-implementation of the migration runner.
     */
    private function migrate(string $dbPath, ?string $version, SymfonyStyle $io): ?int
    {
        $arguments = ['doctrine:migrations:migrate', '--no-interaction'];
        if ($version !== null) {
            $arguments[] = $version;
        }

        $migrate = new Process(
            PhpCliCommand::forScript(\PHP_BINARY, $this->projectDir.'/bin/console', ...$arguments),
            $this->projectDir,
            [
                'DATABASE_URL' => 'sqlite:///'.$dbPath,
                // The parent's Dotenv marks DATABASE_URL as "loaded from .env"; inherited by the
                // child, that marker lets the child's Dotenv overwrite our override.
                'SYMFONY_DOTENV_VARS' => false,
            ],
        );
        $migrate->setTimeout(300);
        $migrate->run();
        if (!$migrate->isSuccessful()) {
            $io->error('Migrations failed:');
            $io->writeln($migrate->getOutput().$migrate->getErrorOutput());

            return $migrate->getExitCode() ?: Command::FAILURE;
        }

        return null;
    }
}
