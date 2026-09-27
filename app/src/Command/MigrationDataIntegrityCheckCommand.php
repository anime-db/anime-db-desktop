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
 * not by this throwaway connection, see connect()), `DROP TABLE` performs an implicit DELETE of
 * every row first, which fires `ON DELETE CASCADE` on any table referencing it — silently wiping
 * children unless the migration toggles the pragma off for the rebuild (see
 * Version20260801000003). Separately, `anime_fts` addresses `anime_name` rows as
 * `rowid = -anime_name.id` (Version20260713000000); `DROP TABLE` fires no triggers, so a rebuild
 * that drops `id` from its `INSERT ... SELECT` re-numbers the surviving rows via AUTOINCREMENT and
 * strands the old `anime_fts` rows pointing at ids that no longer exist (Version20260923000000).
 *
 * The fixture is inserted directly via SQL, not through application code, so this check does not
 * depend on business logic and only exercises the schema itself.
 *
 * Tables cannot all be seeded at once: at the start of the chain none of them exist yet. Instead,
 * the fixture is inserted in two batches, each right after the last migration that creates a
 * table it needs: EARLY_FIXTURE_TABLES once every up() rebuild migration up to and including the
 * `anime` rebuild (Version20260801000003) still lies ahead, and LATE_FIXTURE_TABLES (`downloads`,
 * `anime_sync_state`) once their own tables exist, created by later migrations, with only the
 * `anime_name` rebuild (Version20260923000000) still ahead of them. That covers every up()
 * rebuild migration in the current chain without needing a pause between every single migration.
 */
#[AsCommand(name: 'app:migrations:check-data-integrity', description: 'Run every migration over a populated database and check that no data was lost or desynced')]
final class MigrationDataIntegrityCheckCommand extends Command
{
    /** Last migration before Version20260801000003, the `anime` table rebuild. */
    private const AFTER_EARLY_TABLES_VERSION = 'DoctrineMigrations\Version20260801000002';

    /** Last migration before Version20260923000000, the `anime_name` table rebuild. */
    private const AFTER_LATE_TABLES_VERSION = 'DoctrineMigrations\Version20260812000001';

    /** Tables that exist by AFTER_EARLY_TABLES_VERSION, seeded with one `anime` row and one row per child. */
    private const EARLY_FIXTURE_TABLES = [
        'anime', 'anime_genres', 'anime_studios', 'anime_labels', 'anime_name',
        'anime_image', 'anime_source', 'anime_external_id', 'anime_description', 'anime_plugin_data',
    ];

    /** Tables that exist only from AFTER_LATE_TABLES_VERSION onwards. */
    private const LATE_FIXTURE_TABLES = ['downloads', 'anime_sync_state'];

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
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

            $pdo = $this->connect($dbPath);
            $fixture = $this->insertEarlyFixture($pdo);
            unset($pdo);

            $exitCode = $this->migrate($dbPath, self::AFTER_LATE_TABLES_VERSION, $io);
            if ($exitCode !== null) {
                return $exitCode;
            }

            $pdo = $this->connect($dbPath);
            $violations = [...$violations, ...$this->verifyCounts($pdo, $fixture['counts'])];
            $violations = [...$violations, ...$this->verifyIds($pdo, 'anime_name', $fixture['nameIds'])];
            $fixture['counts'] = [...$fixture['counts'], ...$this->insertLateFixture($pdo, $fixture['animeId'])];
            unset($pdo);

            $exitCode = $this->migrate($dbPath, null, $io);
            if ($exitCode !== null) {
                return $exitCode;
            }

            $pdo = $this->connect($dbPath);
            $violations = [...$violations, ...$this->verifyCounts($pdo, $fixture['counts'])];
            $violations = [...$violations, ...$this->verifyIds($pdo, 'anime_name', $fixture['nameIds'])];
            $violations = [...$violations, ...$this->verifyFtsSync($pdo, $fixture['names'], $fixture['animeId'])];
            $violations = [...$violations, ...$this->verifyCascadeDelete($pdo, $fixture['animeId'])];
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

    /**
     * A raw connection to the database file, bypassing the application container: the
     * EnableForeignKeys middleware (see gotchas.md) only wraps connections built through the
     * container, so "PRAGMA foreign_keys = ON" has to be set by hand here for cascades and FK
     * checks to have any effect on inserts and deletes made through this connection.
     */
    private function connect(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:'.$dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Seeds one `anime` row and one row in every table already created by
     * AFTER_EARLY_TABLES_VERSION: the tables the `anime` rebuild (Version20260801000003) must not
     * cascade-wipe, plus two `anime_name` rows with a deliberate gap in their ids (5 and 9, not 1
     * and 2) so that a rebuild which drops `id` from its `INSERT ... SELECT` and lets AUTOINCREMENT
     * renumber the rows from scratch is distinguishable from a correct, id-preserving one.
     *
     * @return array{animeId: int, nameIds: list<int>, names: array<int, string>, counts: array<string, int>}
     */
    private function insertEarlyFixture(\PDO $pdo): array
    {
        $pdo->exec("INSERT INTO studio (id, name) VALUES (1, 'Sunrise')");
        $pdo->exec("INSERT INTO label (id, name) VALUES (1, 'Favorites')");

        $pdo->exec('INSERT INTO anime (id, title, watch_status, type, date_add, date_update) '
            ."VALUES (1, 'Cowboy Bebop', 'completed', 'tv', 0, 0)");
        $animeId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO anime_genres (anime_id, genre_code) VALUES ({$animeId}, 'sci-fi')");
        $pdo->exec("INSERT INTO anime_studios (anime_id, studio_id) VALUES ({$animeId}, 1)");
        $pdo->exec("INSERT INTO anime_labels (anime_id, label_id) VALUES ({$animeId}, 1)");
        $pdo->exec("INSERT INTO anime_image (anime_id, source) VALUES ({$animeId}, 'cover.jpg')");
        $pdo->exec("INSERT INTO anime_source (anime_id, url) VALUES ({$animeId}, 'https://example.test/cowboy-bebop')");
        $pdo->exec("INSERT INTO anime_external_id (anime_id, plugin_id, external_id) VALUES ({$animeId}, 'mal', '1')");
        $pdo->exec("INSERT INTO anime_description (anime_id, locale, description) VALUES ({$animeId}, 'en', 'Bounty hunters in space.')");
        $pdo->exec("INSERT INTO anime_plugin_data (anime_id, plugin_id, payload) VALUES ({$animeId}, 'mal', '{}')");

        $names = [5 => 'Cowboy Bebop', 9 => 'Kaubbooi Bibappu'];
        $pdo->exec("INSERT INTO anime_name (id, anime_id, name, type) VALUES (5, {$animeId}, 'Cowboy Bebop', 'original')");
        // A throwaway row between the two kept ids, deleted right away, is what actually creates
        // the gap: SQLite would otherwise hand out 1 and 2 to the very first two anime_name rows
        // regardless of whether the rebuild preserves ids, making the two indistinguishable.
        $pdo->exec("INSERT INTO anime_name (id, anime_id, name, type) VALUES (6, {$animeId}, 'discarded', 'synonym')");
        $pdo->exec('DELETE FROM anime_name WHERE id = 6');
        $pdo->exec("INSERT INTO anime_name (id, anime_id, name, type) VALUES (9, {$animeId}, 'Kaubooi Bibappu', 'synonym')");
        $names[9] = 'Kaubooi Bibappu';

        $counts = [];
        foreach (self::EARLY_FIXTURE_TABLES as $table) {
            $counts[$table] = $this->countRows($pdo, $table);
        }

        return ['animeId' => $animeId, 'nameIds' => [5, 9], 'names' => $names, 'counts' => $counts];
    }

    /**
     * Seeds `downloads` and `anime_sync_state`, only created by migrations that run after
     * AFTER_EARLY_TABLES_VERSION (see the class docblock).
     *
     * @return array<string, int>
     */
    private function insertLateFixture(\PDO $pdo, int $animeId): array
    {
        $pdo->exec('INSERT INTO downloads (anime_id, info_hash, status, date_add) '
            ."VALUES ({$animeId}, '0123456789abcdef0123456789abcdef01234567', 'downloading', 0)");
        $pdo->exec('INSERT INTO anime_sync_state (anime_id, participant_id, last_status, last_updated_at) '
            ."VALUES ({$animeId}, 'mal', 'watching', 0)");

        $counts = [];
        foreach (self::LATE_FIXTURE_TABLES as $table) {
            $counts[$table] = $this->countRows($pdo, $table);
        }

        return $counts;
    }

    /**
     * @param array<string, int> $expectedCounts table => row count recorded right after it was seeded
     *
     * @return list<string>
     */
    private function verifyCounts(\PDO $pdo, array $expectedCounts): array
    {
        $violations = [];
        foreach ($expectedCounts as $table => $expected) {
            $actual = $this->countRows($pdo, $table);
            if ($actual !== $expected) {
                $violations[] = \sprintf('%s: expected %d row(s), found %d', $table, $expected, $actual);
            }
        }

        return $violations;
    }

    private function countRows(\PDO $pdo, string $table): int
    {
        $statement = $pdo->query("SELECT COUNT(*) FROM {$table}");
        \assert($statement instanceof \PDOStatement);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<int> $expectedIds
     *
     * @return list<string>
     */
    private function verifyIds(\PDO $pdo, string $table, array $expectedIds): array
    {
        $statement = $pdo->query("SELECT id FROM {$table} ORDER BY id");
        \assert($statement instanceof \PDOStatement);
        $actualIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
        sort($expectedIds);

        if ($actualIds !== $expectedIds) {
            return [\sprintf(
                '%s: expected ids [%s], found [%s]',
                $table,
                implode(', ', $expectedIds),
                implode(', ', $actualIds),
            )];
        }

        return [];
    }

    /**
     * Checks that the rows seeded before the `anime_name` rebuild (Version20260923000000) still
     * address the same `anime_fts` row at `rowid = -id`, and that a fresh insert made after the
     * whole chain still reaches `anime_fts` at the id it was actually given.
     *
     * @param array<int, string> $expectedNames anime_name.id => anime_name.name
     *
     * @return list<string>
     */
    private function verifyFtsSync(\PDO $pdo, array $expectedNames, int $animeId): array
    {
        $violations = [];
        foreach ($expectedNames as $id => $expectedName) {
            $statement = $pdo->prepare('SELECT name FROM anime_fts WHERE rowid = ?');
            $statement->execute([-$id]);
            $actualName = $statement->fetchColumn();
            if ($actualName !== $expectedName) {
                $violations[] = \sprintf(
                    "anime_fts: expected row at rowid=%d (anime_name.id=%d) to hold name '%s', found %s",
                    -$id,
                    $id,
                    $expectedName,
                    $actualName === false ? 'no row' : "'{$actualName}'",
                );
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO anime_name (anime_id, name, normalized_name, locale, role) VALUES (?, ?, ?, NULL, ?)',
        );
        $insert->execute([$animeId, 'Post-migration name', 'post-migration name', 'synonym']);
        $newId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare('SELECT name FROM anime_fts WHERE rowid = ?');
        $statement->execute([-$newId]);
        $ftsName = $statement->fetchColumn();
        if ($ftsName !== 'Post-migration name') {
            $violations[] = \sprintf(
                'anime_fts: inserting anime_name.id=%d after the migration chain did not reach anime_fts at rowid=%d, found %s',
                $newId,
                -$newId,
                $ftsName === false ? 'no row' : "'{$ftsName}'",
            );
        }

        return $violations;
    }

    /**
     * Deletes the fixture `anime` row and checks that every child table it seeded is empty
     * afterwards, proving "PRAGMA foreign_keys = ON" and the ON DELETE CASCADE constraints
     * declared by the migrations still hold at the end of the chain.
     *
     * @return list<string>
     */
    private function verifyCascadeDelete(\PDO $pdo, int $animeId): array
    {
        $statement = $pdo->prepare('DELETE FROM anime WHERE id = ?');
        $statement->execute([$animeId]);

        $violations = [];
        foreach ([...self::EARLY_FIXTURE_TABLES, ...self::LATE_FIXTURE_TABLES] as $table) {
            if ($table === 'anime') {
                continue;
            }

            $statement = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE anime_id = {$animeId}");
            \assert($statement instanceof \PDOStatement);
            $remaining = (int) $statement->fetchColumn();
            if ($remaining !== 0) {
                $violations[] = \sprintf(
                    '%s: %d row(s) for anime_id=%d survived deleting the parent anime row',
                    $table,
                    $remaining,
                    $animeId,
                );
            }
        }

        return $violations;
    }
}
