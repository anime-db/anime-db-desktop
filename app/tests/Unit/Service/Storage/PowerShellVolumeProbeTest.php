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

            return new Process(['php', '-r', 'echo \'{"label":"Backup","driveType":"Fixed","busType":"USB"}\';']);
        });

        $volume = $probe->probe('D');
        $this->assertSame('Backup', $volume?->label);
        $this->assertSame('Fixed', $volume->driveType);
        $this->assertSame('USB', $volume->busType);
        $this->assertIsArray($command);
        $this->assertSame('powershell', $command[0]);
        $script = $command[array_key_last($command)];
        $this->assertStringContainsString("DriveInfo]::new('D')", $script);
        $this->assertStringContainsString("Get-Partition -DriveLetter 'D' -ErrorAction Stop | Get-Disk -ErrorAction Stop", $script);
        $this->assertSame(3, substr_count($script, 'try{'));
    }

    public function testMissingTypeFieldsComeBackAsNull(): void
    {
        $probe = new PowerShellVolumeProbe(windows: true, processFactory: static fn (): Process => new Process(['php', '-r', 'echo \'{"label":null,"driveType":"Fixed","busType":null}\';']));

        $volume = $probe->probe('C');
        $this->assertNotNull($volume);
        $this->assertNull($volume->label);
        $this->assertSame('Fixed', $volume->driveType);
        $this->assertNull($volume->busType);
    }

    public function testFailedRunAnswersNull(): void
    {
        $probe = new PowerShellVolumeProbe(windows: true, processFactory: static fn (): Process => new Process(['php', '-r', 'exit(1);']));

        $this->assertNull($probe->probe('D'));
    }
}
