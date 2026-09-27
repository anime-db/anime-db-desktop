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

namespace App\Service\Migration;

/**
 * Fixture seeding and violation detection for {@see \App\Command\MigrationDataIntegrityCheckCommand},
 * factored out into a plain \PDO-based service so its detection logic (verify*()) can be unit
 * tested directly against a hand-corrupted database, without having to break a real migration
 * file to prove each check actually fails when it should.
 */
final class MigrationDataIntegrityChecker
{
    /** Tables that exist by the time the `anime` rebuild (Version20260801000003) runs, seeded with one `anime` row and one row per child. */
    public const EARLY_FIXTURE_TABLES = [
        'anime', 'anime_genres', 'anime_studios', 'anime_labels', 'anime_themes', 'anime_name',
        'anime_image', 'anime_source', 'anime_external_id', 'anime_description', 'anime_plugin_data',
    ];

    /** `downloads`, seeded right after Version20260809000000 creates it. */
    public const MID_FIXTURE_TABLES = ['downloads'];

    /** `anime_sync_state`, seeded once Version20260812000001 creates it. */
    public const LATE_FIXTURE_TABLES = ['anime_sync_state'];

    /**
     * A raw connection to the database file, bypassing the application container: the
     * EnableForeignKeys middleware (see gotchas.md) only wraps connections built through the
     * container, so "PRAGMA foreign_keys = ON" has to be set by hand here for cascades and FK
     * checks to have any effect on inserts and deletes made through this connection.
     */
    public function connect(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:'.$dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Seeds one `anime` row and one row in every table in EARLY_FIXTURE_TABLES, plus two
     * `anime_name` rows with a deliberate gap in their ids (5 and 9, not 1 and 2) so that a
     * rebuild which drops `id` from its `INSERT ... SELECT` and lets AUTOINCREMENT renumber the
     * rows from scratch is distinguishable from a correct, id-preserving one.
     *
     * @return array{animeId: int, nameIds: list<int>, names: array<int, string>, counts: array<string, int>}
     */
    public function insertEarlyFixture(\PDO $pdo): array
    {
        $pdo->exec("INSERT INTO studio (id, name) VALUES (1, 'Sunrise')");
        $pdo->exec("INSERT INTO label (id, name) VALUES (1, 'Favorites')");

        $pdo->exec('INSERT INTO anime (id, title, watch_status, type, date_add, date_update) '
            ."VALUES (1, 'Cowboy Bebop', 'completed', 'tv', 0, 0)");
        $animeId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO anime_genres (anime_id, genre_code) VALUES ({$animeId}, 'sci-fi')");
        $pdo->exec("INSERT INTO anime_studios (anime_id, studio_id) VALUES ({$animeId}, 1)");
        $pdo->exec("INSERT INTO anime_labels (anime_id, label_id) VALUES ({$animeId}, 1)");
        $pdo->exec("INSERT INTO anime_themes (anime_id, theme_code) VALUES ({$animeId}, 'mecha')");
        $pdo->exec("INSERT INTO anime_image (anime_id, source) VALUES ({$animeId}, 'cover.jpg')");
        $pdo->exec("INSERT INTO anime_source (anime_id, url) VALUES ({$animeId}, 'https://example.test/cowboy-bebop')");
        $pdo->exec("INSERT INTO anime_external_id (anime_id, plugin_id, external_id) VALUES ({$animeId}, 'mal', '1')");
        $pdo->exec("INSERT INTO anime_description (anime_id, locale, description) VALUES ({$animeId}, 'en', 'Bounty hunters in space.')");
        $pdo->exec("INSERT INTO anime_plugin_data (anime_id, plugin_id, payload) VALUES ({$animeId}, 'mal', '{}')");

        $names = [5 => 'Cowboy Bebop', 9 => 'Kaubooi Bibappu'];
        foreach ($names as $id => $name) {
            $type = $id === 5 ? 'original' : 'synonym';
            $pdo->exec("INSERT INTO anime_name (id, anime_id, name, type) VALUES ({$id}, {$animeId}, '{$name}', '{$type}')");
            // A throwaway row right after id 5, deleted immediately, is what actually creates the
            // gap: SQLite would otherwise hand out 5 and 6 to these two rows regardless of whether
            // a rebuild preserves ids, making the two indistinguishable.
            if ($id === 5) {
                $pdo->exec("INSERT INTO anime_name (id, anime_id, name, type) VALUES (6, {$animeId}, 'discarded', 'synonym')");
                $pdo->exec('DELETE FROM anime_name WHERE id = 6');
            }
        }

        $counts = [];
        foreach (self::EARLY_FIXTURE_TABLES as $table) {
            $counts[$table] = $this->countRows($pdo, $table);
        }

        return ['animeId' => $animeId, 'nameIds' => [5, 9], 'names' => $names, 'counts' => $counts];
    }

    /**
     * Seeds `downloads`, only created by Version20260809000000.
     *
     * @return array<string, int>
     */
    public function insertMidFixture(\PDO $pdo, int $animeId): array
    {
        $pdo->exec('INSERT INTO downloads (anime_id, info_hash, status, date_add) '
            ."VALUES ({$animeId}, '0123456789abcdef0123456789abcdef01234567', 'downloading', 0)");

        $counts = [];
        foreach (self::MID_FIXTURE_TABLES as $table) {
            $counts[$table] = $this->countRows($pdo, $table);
        }

        return $counts;
    }

    /**
     * Seeds `anime_sync_state`, only created by Version20260812000001.
     *
     * @return array<string, int>
     */
    public function insertLateFixture(\PDO $pdo, int $animeId): array
    {
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
    public function verifyCounts(\PDO $pdo, array $expectedCounts): array
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

    /**
     * @param list<int> $expectedIds
     *
     * @return list<string>
     */
    public function verifyIds(\PDO $pdo, string $table, array $expectedIds): array
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
    public function verifyFtsSync(\PDO $pdo, array $expectedNames, int $animeId): array
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
    public function verifyCascadeDelete(\PDO $pdo, int $animeId): array
    {
        $statement = $pdo->prepare('DELETE FROM anime WHERE id = ?');
        $statement->execute([$animeId]);

        $violations = [];
        foreach ([...self::EARLY_FIXTURE_TABLES, ...self::MID_FIXTURE_TABLES, ...self::LATE_FIXTURE_TABLES] as $table) {
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

    private function countRows(\PDO $pdo, string $table): int
    {
        $statement = $pdo->query("SELECT COUNT(*) FROM {$table}");
        \assert($statement instanceof \PDOStatement);

        return (int) $statement->fetchColumn();
    }
}
