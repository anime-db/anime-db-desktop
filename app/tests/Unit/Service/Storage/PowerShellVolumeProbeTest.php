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

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\PowerShellVolumeProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PowerShellVolumeProbeTest extends TestCase
{
    public function testAnswersNullOutsideWindows(): void
    {
        $this->assertNull((new PowerShellVolumeProbe(windows: false))->probe('D'));
    }

    /** @return array<string, array{string}> */
    public static function invalidLetters(): array
    {
        return [
            'injection' => ["D');calc;('"],
            'two letters' => ['DD'],
            'trailing newline' => ["D\n"],
            'digit' => ['1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidLetters')]
    public function testInvalidLetterNeverCreatesAProcess(string $letter): void
    {
        $created = 0;
        $probe = new PowerShellVolumeProbe(windows: true, processFactory: static function () use (&$created): Process {
            ++$created;

            return new Process(['true']);
        });

        $this->assertNull($probe->buildCommand($letter));
        $this->assertNull($probe->probe($letter));
        $this->assertSame(0, $created);
    }

    public function testValidLetterBuildsTheScriptAndRunsTheFactoryProcess(): void
    {
        $command = null;
        $probe = new PowerShellVolumeProbe(windows: true, processFactory: static function (array $given) use (&$command): Process {
            $command = $given;

            return new Process(['php', '-r', 'echo \'{"label":"Backup"}\';']);
        });

        $this->assertSame('Backup', $probe->probe('D')?->label);
        $this->assertIsArray($command);
        $this->assertSame('powershell', $command[0]);
        $this->assertStringContainsString("DriveInfo]::new('D')", $command[array_key_last($command)]);
    }
}
