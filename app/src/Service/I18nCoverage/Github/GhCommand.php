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

namespace App\Service\I18nCoverage\Github;

use Symfony\Component\Process\Process;

/**
 * Thin, shared wrapper around shelling out to the `gh` CLI — the same tool, and the same
 * `GH_TOKEN`/`GITHUB_TOKEN`-from-environment authentication, that `anime-db-plugins`' own
 * `tools/src/GhReleaseAssetSource.php` uses. Every `Github\*` real implementation in this
 * namespace goes through this one class rather than shelling out itself, so a failing `gh`
 * invocation is reported the same way (message + exit code) everywhere.
 *
 * None of this is used by any test in this codebase — see the interfaces in the parent namespace
 * for what stands in for it there.
 */
final class GhCommand
{
    /**
     * @param list<string> $arguments passed to `gh` as-is, e.g. ['issue', 'list', '--repo', $repo]
     */
    public function run(array $arguments): string
    {
        $process = new Process(['gh', ...$arguments]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('gh %s failed (exit %d): %s', implode(' ', $arguments), (int) $process->getExitCode(), trim($process->getErrorOutput()) !== '' ? $process->getErrorOutput() : $process->getOutput()));
        }

        return trim($process->getOutput());
    }
}
