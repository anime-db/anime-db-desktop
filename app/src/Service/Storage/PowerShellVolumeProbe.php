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

namespace App\Service\Storage;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Volume probe backed by one PowerShell run that prints a single JSON line. Windows only: on
 * other systems there is nothing to ask and the probe answers null.
 *
 * `vol` is not used because its output is localized. The console output encoding is set to UTF-8
 * explicitly, as PowerShell 5.1 writes to a pipe in the OEM code page. Every property sits in its
 * own `try`, so a failure of one (an empty card reader throws on a label read) keeps the others.
 * The process has a timeout: a disconnected network drive can hang a label read for a long time.
 */
final class PowerShellVolumeProbe implements VolumeProbeInterface
{
    public function __construct(
        private readonly float $timeout = 5.0,
        private readonly bool $windows = \PHP_OS_FAMILY === 'Windows',
    ) {
    }

    public function probe(string $letter): ?VolumeInfo
    {
        // The letter goes into a command line, so only a single ASCII letter passes.
        if (!$this->windows || preg_match('/^[A-Za-z]$/', $letter) !== 1) {
            return null;
        }

        $script = '[Console]::OutputEncoding=[Text.Encoding]::UTF8;'
            .'$label=$null;'
            ."try{\$label=[System.IO.DriveInfo]::new('".$letter."').VolumeLabel}catch{};"
            .'ConvertTo-Json -Compress -InputObject @{label=$label}';

        $process = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script], null, null, null, $this->timeout);

        try {
            $process->run();
        } catch (ProcessException) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $data = json_decode(trim($process->getOutput()), true);
        if (!\is_array($data)) {
            return null;
        }

        $label = $data['label'] ?? null;

        return new VolumeInfo(\is_string($label) ? $label : null);
    }
}
