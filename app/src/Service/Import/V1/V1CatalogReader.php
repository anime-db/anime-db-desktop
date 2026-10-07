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

namespace App\Service\Import\V1;

use App\Entity\Import\V1AnimeRecord;
use App\Entity\Import\V1StorageRecord;
use App\Service\Import\Exception\InvalidV1InstallationException;
use Psr\Log\LoggerInterface;

/**
 * Reads an AnimeDB v1 installation: its own read-only PDO and plain SQL, no Doctrine entities and
 * no second connection in doctrine.yaml, so the foreign schema never reaches `schema:validate`,
 * the mapping check or the golden schema.
 *
 * The input is the installation directory, not the database file: the database sits at
 * `app/Resources/anime.db`, the covers under `web/media/`.
 */
final class V1CatalogReader
{
    public const string DATABASE_PATH = 'app/Resources/anime.db';
    public const string MEDIA_PATH = 'web/media';

    private const array REQUIRED_TABLES = ['item', 'name', 'source'];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function databasePath(string $installationDir): string
    {
        return rtrim($installationDir, '/\\').'/'.self::DATABASE_PATH;
    }

    /** Null when the installation has no `web/media/` — an import without covers, not a refusal. */
    public static function mediaDir(string $installationDir): ?string
    {
        $dir = rtrim($installationDir, '/\\').'/'.self::MEDIA_PATH;

        return is_dir($dir) ? $dir : null;
    }

    /**
     * @return list<V1AnimeRecord>
     *
     * @throws InvalidV1InstallationException
     */
    public function read(string $installationDir): array
    {
        $databasePath = self::databasePath($installationDir);
        $pdo = $this->open($databasePath);

        $names = $this->groupByItem($pdo, 'SELECT item_id, name FROM name ORDER BY rowid');
        $sources = $this->groupByItem($pdo, 'SELECT item_id, url FROM source ORDER BY rowid');

        $labels = [];
        $genres = [];
        if ($this->hasTable($pdo, 'label') && $this->hasTable($pdo, 'items_labels')) {
            $labels = $this->groupByItem($pdo, 'SELECT il.item_id, l.name FROM items_labels il JOIN label l ON l.id = il.label_id ORDER BY l.id');
        }
        if ($this->hasTable($pdo, 'genre') && $this->hasTable($pdo, 'items_genres')) {
            $genres = $this->groupByItem($pdo, 'SELECT ig.item_id, g.name FROM items_genres ig JOIN genre g ON g.id = ig.genre_id JOIN item i ON i.id = ig.item_id ORDER BY g.id');
            $this->logDanglingGenreLinks($pdo);
        }

        $storages = $this->readStorages($pdo);
        $studios = $this->readNameDictionary($pdo, 'studio');

        $columns = $this->columns($pdo, 'item');
        $storageColumn = $this->firstColumn($columns, ['storage', 'storage_id']);
        $studioColumn = $this->firstColumn($columns, ['studio', 'studio_id']);
        $typeColumn = $this->firstColumn($columns, ['type', 'type_id']);
        $countryColumn = $this->firstColumn($columns, ['country', 'country_id']);

        $records = [];
        foreach ($pdo->query('SELECT * FROM item ORDER BY rowid') ?: [] as $row) {
            /** @var array<string, mixed> $row */
            $id = (int) $row['id'];
            $storageId = $storageColumn !== null ? $this->string($row[$storageColumn] ?? null) : null;

            $studio = $studioColumn !== null ? $this->string($row[$studioColumn] ?? null) : null;
            if ($studio !== null) {
                $studio = $studios[$studio] ?? $studio;
            }

            $records[] = new V1AnimeRecord(
                id: $id,
                title: (string) ($row['name'] ?? ''),
                type: $typeColumn !== null ? $this->string($row[$typeColumn] ?? null) : null,
                dateAdd: $this->dateTime($row['date_add'] ?? null),
                dateUpdate: $this->dateTime($row['date_update'] ?? null),
                datePremiere: $this->date($row['date_premiere'] ?? null),
                dateEnd: $this->date($row['date_end'] ?? null),
                duration: $this->int($row['duration'] ?? null),
                rating: $this->int($row['rating'] ?? null),
                country: $countryColumn !== null ? $this->string($row[$countryColumn] ?? null) : null,
                studio: $studio,
                summary: $this->string($row['summary'] ?? null),
                episodesNumber: $this->int($row['episodes_number'] ?? null),
                episodes: $this->string($row['episodes'] ?? null),
                translate: $this->string($row['translate'] ?? null),
                fileInfo: $this->string($row['file_info'] ?? null),
                cover: $this->string($row['cover'] ?? null),
                path: $this->string($row['path'] ?? null),
                storage: $storageId !== null ? ($storages[$storageId] ?? null) : null,
                names: $names[$id] ?? [],
                sources: $sources[$id] ?? [],
                labels: $labels[$id] ?? [],
                genres: $genres[$id] ?? [],
            );
        }

        return $records;
    }

    private function open(string $databasePath): \PDO
    {
        $notV1 = new InvalidV1InstallationException(
            InvalidV1InstallationException::REASON_NOT_V1_INSTALLATION,
            ['%path%' => $databasePath],
            \sprintf('Not an AnimeDB v1 installation: no readable database at "%s"', $databasePath),
        );

        if (!is_file($databasePath) || !is_readable($databasePath)) {
            throw $notV1;
        }

        try {
            // The URI form with mode=ro is the only way to open SQLite for reading only: a plain
            // `sqlite:<path>` opens it read-write, and nothing may ever be written next to v1.
            $pdo = new \PDO('sqlite:file:'.rawurlencode($databasePath).'?mode=ro');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            foreach (self::REQUIRED_TABLES as $table) {
                if (!$this->hasTable($pdo, $table)) {
                    throw $notV1;
                }
            }
        } catch (\PDOException) {
            throw $notV1;
        }

        return $pdo;
    }

    private function hasTable(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info('.$table.')') ?: [] as $row) {
            $columns[] = (string) $row['name'];
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $candidates
     */
    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (\in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<int, list<string>> */
    private function groupByItem(\PDO $pdo, string $sql): array
    {
        $grouped = [];
        foreach ($pdo->query($sql) ?: [] as $row) {
            $values = array_values($row);
            $grouped[(int) $values[0]][] = (string) $values[1];
        }

        return $grouped;
    }

    /** @return array<string, V1StorageRecord> by v1 storage id */
    private function readStorages(\PDO $pdo): array
    {
        if (!$this->hasTable($pdo, 'storage')) {
            return [];
        }

        $storages = [];
        foreach ($pdo->query('SELECT * FROM storage') ?: [] as $row) {
            $storages[(string) $row['id']] = new V1StorageRecord(
                (string) ($row['name'] ?? ''),
                $this->string($row['path'] ?? null),
                $this->string($row['type'] ?? null),
            );
        }

        return $storages;
    }

    /**
     * v1 may keep the studio as a reference to a dictionary table or as plain text.
     *
     * @return array<string, string> id => name, empty when there is no such table
     */
    private function readNameDictionary(\PDO $pdo, string $table): array
    {
        if (!$this->hasTable($pdo, $table) || !\in_array('name', $this->columns($pdo, $table), true)) {
            return [];
        }

        $dictionary = [];
        foreach ($pdo->query('SELECT id, name FROM '.$table) ?: [] as $row) {
            $dictionary[(string) $row['id']] = (string) $row['name'];
        }

        return $dictionary;
    }

    /** v1's own debris (links left by deletions inside v1): one log line, nothing in the report. */
    private function logDanglingGenreLinks(\PDO $pdo): void
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM items_genres ig LEFT JOIN genre g ON g.id = ig.genre_id LEFT JOIN item i ON i.id = ig.item_id WHERE g.id IS NULL OR i.id IS NULL');
        $dangling = $statement === false ? 0 : (int) $statement->fetchColumn();
        if ($dangling > 0) {
            $this->logger->info('Skipped {count} dangling genre links in the v1 database.', ['count' => $dangling]);
        }
    }

    private function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function dateTime(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** A v1 date is a day; it is kept at midnight, the way the v2 seed data keeps its dates. */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->string($value);

        return $value === null ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10)) ?: null);
    }
}
