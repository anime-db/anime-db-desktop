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

namespace App\Tests\Fixtures\Service\Download;

use Doctrine\DBAL\Connection;

/**
 * Simulates a concurrent writer (DownloadCompletionPoller) making the DELETE issued by
 * {@see \App\Service\Download\DownloadUnlinkService::unlink()} fail with a database error, instead
 * of just losing the optimistic-lock race (0 affected rows) — used by
 * DownloadUnlinkServiceTest to prove the transaction is rolled back rather than left open on the
 * connection (issue #857 review).
 */
final class ThrowingOnDeleteConnection extends Connection
{
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_starts_with($sql, 'DELETE FROM downloads')) {
            throw new \RuntimeException('simulated database is locked');
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
