<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use Psr\Log\AbstractLogger;

/**
 * Counts every DBAL log entry (each corresponds to one prepared statement execution, see
 * Doctrine\DBAL\Logging\Statement::execute()), so tests can assert on the number of SQL
 * statements a code path issues instead of only on its result (issue #207 N+1 regression test).
 */
final class QueryCountingLogger extends AbstractLogger
{
    private int $count = 0;

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        ++$this->count;
    }

    public function reset(): void
    {
        $this->count = 0;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
