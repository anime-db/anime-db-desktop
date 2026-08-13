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

namespace App\Service\JobLock;

use App\Service\JobLock\Exception\ProcessLivenessCheckException;

/**
 * The application only ships for Windows (see .claude-docs/architecture.md), where POSIX
 * kill($pid, 0) is unavailable. PowerShell's Get-Process is used instead of `wmic` — Microsoft
 * removes wmic by default starting with Windows 11 24H2, while powershell.exe has shipped
 * since Windows 7, safely below our minimum supported version (Windows 10).
 */
final class WindowsProcessLivenessChecker implements ProcessLivenessChecker
{
    public function getStartedAt(int $pid): ?\DateTimeImmutable
    {
        exec(
            sprintf(
                'powershell -NoProfile -Command "$p = Get-Process -Id %d -ErrorAction SilentlyContinue; if ($p) { $p.StartTime.ToString(\'o\') }"',
                $pid,
            ),
            $output,
            $resultCode,
        );

        // A non-zero exit code means the powershell command itself failed to run (disabled
        // exec(), missing binary, etc.) — not that the process doesn't exist (that case exits 0
        // with empty output). This must not be collapsed into "no such process": the caller
        // relies on the heartbeat staleness check as the safety net when the check is unknown,
        // and a false "dead" here would bypass it entirely.
        if ($resultCode !== 0) {
            throw new ProcessLivenessCheckException(sprintf('Failed to query process %d, exit code %d.', $pid, $resultCode));
        }

        $startedAt = trim(implode('', $output));

        if ($startedAt === '') {
            return null;
        }

        return new \DateTimeImmutable($startedAt);
    }
}
