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

namespace App\Service\JobLock;

/**
 * The application only ships for Windows (see .claude-docs/architecture.md), where POSIX
 * kill($pid, 0) is unavailable. `tasklist` is the standard way to query a process by PID there.
 */
final class WindowsProcessLivenessChecker implements ProcessLivenessChecker
{
    public function isRunning(int $pid): bool
    {
        exec(sprintf('tasklist /FI "PID eq %d" /FO CSV /NH', $pid), $output, $resultCode);

        if ($resultCode !== 0) {
            return false;
        }

        foreach ($output as $line) {
            $fields = str_getcsv($line);

            if (isset($fields[1]) && (int) $fields[1] === $pid) {
                return true;
            }
        }

        return false;
    }
}
