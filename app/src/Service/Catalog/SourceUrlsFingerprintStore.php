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

namespace App\Service\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The fingerprint of the link rule set the stored `anime_source.normalized_url` values were last
 * computed by. It lives in `data.db` (the `app_meta` table), so it travels with the data on backup,
 * restore and import.
 */
class SourceUrlsFingerprintStore
{
    public const KEY = 'source_urls_fingerprint';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM app_meta WHERE "key" = ?', [self::KEY]);

        return $value === false ? null : (string) $value;
    }

    public function set(string $fingerprint): void
    {
        $this->connection->executeStatement(
            'INSERT INTO app_meta ("key", value) VALUES (?, ?) ON CONFLICT("key") DO UPDATE SET value = excluded.value',
            [self::KEY, $fingerprint],
        );
    }
}
