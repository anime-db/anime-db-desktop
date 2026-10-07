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

namespace App\Tests\Support;

/**
 * Builds a synthetic AnimeDB v1 installation on disk: `app/Resources/anime.db` with the v1 tables
 * the importer reads, and optionally `web/media/`. {@see self::catalog()} fills it with a
 * catalog shaped like a real 2014–2016 one — every type, the genre dictionary of v1, the
 * statuses and the values the v2 schema refuses — so the import is checked on hundreds of rows,
 * not on the seven demo records.
 */
final class V1DatabaseBuilder
{
    /** The 50 genre names v1 knew. */
    public const array GENRES = [
        'Action', 'Adventure', 'Apocalyptic fiction', 'Cars', 'Comedy', 'Cyberpunk', 'Demons', 'Detective', 'Drama', 'Ecchi',
        'Educational', 'Erotica', 'Fable', 'Fantasy', 'Game', 'Gender Bender', 'Harem', 'Hentai', 'History', 'Horror', 'Josei',
        'Kids', 'Magic', 'Mahoe shoujo', 'Martial arts', 'Mecha', 'Music', 'Mystery', 'Parody', 'Police', 'Psychological',
        'Romance', 'Samurai', 'School', 'Sci-fi', 'Shoujo', 'Shoujo-ai', 'Shounen', 'Shounen-ai', 'Slice of life', 'Space',
        'Sport', 'Steampunk', 'Super Power', 'Supernatural', 'Thriller', 'Vampire', 'War', 'Yaoi', 'Yuri',
    ];

    private const array TYPES = ['tv', 'feature', 'ova', 'featurette', 'tv', 'feature', 'ova', 'ona', 'special', 'music'];

    public readonly string $root;
    public readonly \PDO $pdo;

    /** @var array<string, int> */
    private array $genreIds = [];

    /** @var array<string, int> */
    private array $labelIds = [];

    private function __construct(string $root)
    {
        $this->root = $root;
        mkdir($root.'/app/Resources', 0o777, true);
        $this->pdo = new \PDO('sqlite:'.$root.'/app/Resources/anime.db');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach ([
            'CREATE TABLE storage (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(256) NOT NULL, path VARCHAR(1024) DEFAULT NULL, type VARCHAR(32) DEFAULT NULL)',
            'CREATE TABLE genre (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(128) NOT NULL)',
            'CREATE TABLE label (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(128) NOT NULL)',
            'CREATE TABLE item (id INTEGER PRIMARY KEY AUTOINCREMENT, storage INTEGER DEFAULT NULL, type VARCHAR(32) DEFAULT NULL, country VARCHAR(8) DEFAULT NULL, studio VARCHAR(256) DEFAULT NULL, name VARCHAR(256) NOT NULL, date_add DATETIME NOT NULL, date_update DATETIME DEFAULT NULL, date_premiere DATE DEFAULT NULL, date_end DATE DEFAULT NULL, duration INTEGER DEFAULT NULL, summary TEXT DEFAULT NULL, episodes_number VARCHAR(32) DEFAULT NULL, episodes TEXT DEFAULT NULL, translate TEXT DEFAULT NULL, file_info TEXT DEFAULT NULL, cover VARCHAR(256) DEFAULT NULL, rating INTEGER DEFAULT NULL, path VARCHAR(1024) DEFAULT NULL)',
            'CREATE TABLE name (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER DEFAULT NULL, name VARCHAR(256) NOT NULL)',
            'CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER DEFAULT NULL, url VARCHAR(256) NOT NULL)',
            'CREATE TABLE items_genres (item_id INTEGER NOT NULL, genre_id INTEGER NOT NULL)',
            'CREATE TABLE items_labels (item_id INTEGER NOT NULL, label_id INTEGER NOT NULL)',
        ] as $ddl) {
            $this->pdo->exec($ddl);
        }
    }

    public static function create(string $root): self
    {
        return new self($root);
    }

    /**
     * 194 records spread over the types, with the awkward values of a real catalog mixed in.
     */
    public static function catalog(string $root, int $count = 194): self
    {
        $builder = new self($root);
        foreach (self::GENRES as $genre) {
            $builder->genre($genre);
        }
        $storage = $builder->storage('Anime', '/mnt/anime', 'folder');
        $builder->storage('Backup', null, 'external');
        $builder->label('Online');
        $builder->label('Просмотрено');
        $builder->label('Брошено');
        $genreCount = \count(self::GENRES);

        for ($i = 1; $i <= $count; ++$i) {
            $type = self::TYPES[$i % \count(self::TYPES)];
            $year = 2000 + $i % 15;
            $premiere = \sprintf('%d-%02d-%02d', $year, 1 + $i % 12, 1 + $i % 28);
            $row = [
                'name' => 'Title '.$i,
                'type' => $type,
                'country' => $i % 27 === 0 ? null : 'JP',
                'studio' => 'Studio '.($i % 9),
                'date_add' => \sprintf('%d-%02d-%02d 14:%02d:%02d', 2014 + $i % 3, 1 + $i % 12, 1 + $i % 28, $i % 60, $i % 60),
                'date_update' => \sprintf('%d-%02d-%02d 10:00:00', 2015 + $i % 2, 1 + $i % 12, 1 + $i % 28),
                'date_premiere' => $premiere,
                // A third have no end date: for films it is borrowed from the premiere.
                'date_end' => $i % 3 === 0 ? null : \sprintf('%d-%02d-%02d', $year + 1, 1 + $i % 12, 1 + $i % 28),
                'duration' => $i % 40 === 0 ? 0 : 20 + $i % 90,
                'summary' => $i % 2 === 0 ? 'Summary '.$i : null,
                'episodes_number' => $type === 'tv' ? 12 + $i % 20 : ($i % 7 === 0 ? 3 : 1),
                'episodes' => $i % 3 === 0 ? '1. Episode (01.01.2010, 25 min.)' : null,
                'translate' => $i % 5 === 0 ? 'RUS(ext), JAP+SUB' : null,
                'file_info' => $i % 4 === 0 ? 'BDRip 720p' : null,
                'cover' => \sprintf('%d/01/01/000000/%d.jpg', 2014 + $i % 3, $i),
                'rating' => $i % 60 === 0 ? 4 : null,
                'storage' => $i % 10 === 0 ? $storage : null,
            ];
            // A series with no end date would be flagged for review; keep a few of those too.
            $id = $builder->item($row);

            $builder->name($id, 'Alt '.$i);
            $builder->name($id, 'Alt '.$i);
            $builder->name($id, 'アルト'.$i);
            $builder->name($id, 'Alt'.$i.'　アルト');
            $builder->name($id, 'Альт '.$i);
            $builder->source($id, 'http://myanimelist.net/anime/'.$i.'/');
            $builder->source($id, 'http://anidb.net/perl-bin/animedb.pl?show=anime&aid='.$i);

            foreach ([$i % $genreCount, ($i * 7) % $genreCount, ($i * 13) % $genreCount] as $genreIndex) {
                $builder->itemGenre($id, self::GENRES[$genreIndex]);
            }
            if ($i % 25 === 0) {
                $builder->itemLabel($id, 'Просмотрено');
            }
            if ($i % 28 === 0) {
                $builder->itemLabel($id, 'Online');
            }
            if ($i % 97 === 0) {
                $builder->itemLabel($id, 'Брошено');
            }
        }

        // Debris of v1: links to a genre and an item that are gone.
        $builder->pdo->exec('INSERT INTO items_genres (item_id, genre_id) VALUES (999999, 1), (1, 999999)');

        return $builder;
    }

    public function withMedia(): self
    {
        mkdir($this->root.'/web/media', 0o777, true);

        return $this;
    }

    public function storage(string $name, ?string $path, ?string $type): int
    {
        $this->pdo->prepare('INSERT INTO storage (name, path, type) VALUES (?, ?, ?)')->execute([$name, $path, $type]);

        return (int) $this->pdo->lastInsertId();
    }

    public function genre(string $name): int
    {
        $this->pdo->prepare('INSERT INTO genre (name) VALUES (?)')->execute([$name]);

        return $this->genreIds[$name] = (int) $this->pdo->lastInsertId();
    }

    public function label(string $name): int
    {
        $this->pdo->prepare('INSERT INTO label (name) VALUES (?)')->execute([$name]);

        return $this->labelIds[$name] = (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, scalar|null> $row item columns; `name` is required, the dates default */
    public function item(array $row): int
    {
        $row += ['date_add' => '2014-02-08 14:59:28', 'date_premiere' => '2010-04-01'];
        $columns = array_keys($row);
        $this->pdo->prepare(\sprintf('INSERT INTO item (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, \count($columns), '?'))))
            ->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    public function name(int $itemId, string $name): void
    {
        $this->pdo->prepare('INSERT INTO name (item_id, name) VALUES (?, ?)')->execute([$itemId, $name]);
    }

    public function source(int $itemId, string $url): void
    {
        $this->pdo->prepare('INSERT INTO source (item_id, url) VALUES (?, ?)')->execute([$itemId, $url]);
    }

    public function itemGenre(int $itemId, string $genre): void
    {
        $genreId = $this->genreIds[$genre] ?? $this->genre($genre);
        $this->pdo->prepare('INSERT INTO items_genres (item_id, genre_id) VALUES (?, ?)')->execute([$itemId, $genreId]);
    }

    public function itemLabel(int $itemId, string $label): void
    {
        $labelId = $this->labelIds[$label] ?? $this->label($label);
        $this->pdo->prepare('INSERT INTO items_labels (item_id, label_id) VALUES (?, ?)')->execute([$itemId, $labelId]);
    }
}
