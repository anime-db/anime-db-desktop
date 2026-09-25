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

use App\Command\SchemaCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaCheckCommandTest extends TestCase
{
    private const APP_DIR = __DIR__.'/../../..';

    public function testCleanTreeMatchesGoldenAndLeavesNoTempDirectory(): void
    {
        $before = $this->tempDirs();

        $first = new CommandTester(new SchemaCheckCommand(self::APP_DIR));
        $first->execute([]);
        $second = new CommandTester(new SchemaCheckCommand(self::APP_DIR));
        $second->execute([]);

        $this->assertSame(Command::SUCCESS, $first->getStatusCode(), $first->getDisplay());
        $this->assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
        $this->assertSame($before, $this->tempDirs());
    }

    public function testWriteIsIdempotentAndCheckReportsMissingRow(): void
    {
        $projectDir = $this->fakeProject();
        // Real migrations, but a project dir of our own for the golden file.
        symlink(realpath(self::APP_DIR.'/bin') ?: '', $projectDir.'/bin');
        $this->assertSame(Command::FAILURE, $this->runCommand($projectDir, [])->getStatusCode(), 'missing golden must fail');

        $this->assertSame(Command::SUCCESS, $this->runCommand($projectDir, ['--write' => true])->getStatusCode());
        $written = (string) file_get_contents($projectDir.'/migrations/schema.golden.tsv');
        $this->assertSame((string) file_get_contents(self::APP_DIR.'/migrations/schema.golden.tsv'), $written);

        $lines = explode("\n", trim($written));
        $lost = array_pop($lines);
        file_put_contents($projectDir.'/migrations/schema.golden.tsv', implode("\n", [...$lines, 'index'."\tghost\tX", $lost])."\n");

        $tester = $this->runCommand($projectDir, []);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString("< index\tghost\tX", $tester->getDisplay());
    }

    public function testCheckReportsRowMissingFromGolden(): void
    {
        $projectDir = $this->fakeProject();
        symlink(realpath(self::APP_DIR.'/bin') ?: '', $projectDir.'/bin');
        $lines = explode("\n", trim((string) file_get_contents(self::APP_DIR.'/migrations/schema.golden.tsv')));
        $dropped = array_pop($lines);
        file_put_contents($projectDir.'/migrations/schema.golden.tsv', implode("\n", $lines)."\n");

        $tester = $this->runCommand($projectDir, []);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('> '.$dropped, $tester->getDisplay());
    }

    public function testFailingMigrationsGiveNonZeroExitAndNoComparison(): void
    {
        $projectDir = $this->fakeProject();
        mkdir($projectDir.'/bin');
        file_put_contents($projectDir.'/bin/console', "<?php\nfwrite(STDERR, \"boom\\n\");\nexit(3);\n");
        file_put_contents($projectDir.'/migrations/schema.golden.tsv', '');
        $before = $this->tempDirs();

        $tester = $this->runCommand($projectDir, []);

        $this->assertSame(3, $tester->getStatusCode());
        $this->assertStringContainsString('boom', $tester->getDisplay());
        $this->assertSame($before, $this->tempDirs());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $projectDir, array $input): CommandTester
    {
        $tester = new CommandTester(new SchemaCheckCommand($projectDir));
        $tester->execute($input);

        return $tester;
    }

    private function fakeProject(): string
    {
        $dir = sys_get_temp_dir().'/animedb_schema_check_test_'.bin2hex(random_bytes(6));
        mkdir($dir.'/migrations', 0o777, true);
        register_shutdown_function(static function () use ($dir): void {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($dir);
        });

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function tempDirs(): array
    {
        $dirs = glob(sys_get_temp_dir().'/animedb_schema_check_[0-9a-f]*') ?: [];
        sort($dirs);

        return $dirs;
    }
}
