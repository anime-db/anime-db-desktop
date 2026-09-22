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

namespace App\Tests\Unit\Command;

use App\Command\ImportStagedStatusCommand;
use App\Service\Import\StagedImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportStagedStatusCommandTest extends TestCase
{
    private string $importStagingDir;

    protected function setUp(): void
    {
        $this->importStagingDir = sys_get_temp_dir().'/animedb-staged-status-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->importStagingDir);
    }

    public function testFailsWhenNoValidMarkerIsPresent(): void
    {
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('', trim($tester->getDisplay()));
    }

    public function testPrintsStagedAtAndSourceArchiveAsJsonWhenTheMarkerIsValid(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', json_encode([
            'markerVersion' => StagedImportService::SUPPORTED_MARKER_VERSION,
            'stagedAt' => '2026-09-18T12:34:56Z',
            'sourceArchive' => 'catalog-export.zip',
        ]));

        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = json_decode(trim($tester->getDisplay()), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('catalog-export.zip', $output['sourceArchive']);
        self::assertSame('2026-09-18T12:34:56+00:00', $output['stagedAt']);
    }

    private function createCommand(): ImportStagedStatusCommand
    {
        return new ImportStagedStatusCommand(new StagedImportService(
            $this->importStagingDir,
            sys_get_temp_dir().'/animedb-staged-status-test-rejection-'.uniqid().'.json',
        ));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
