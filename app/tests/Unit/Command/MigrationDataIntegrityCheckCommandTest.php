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

use App\Command\MigrationDataIntegrityCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the check against the project's real migration chain: this is the property the two
 * hand-crafted migration tests (Version20260801000003Test, Version20260923000000Test) cannot
 * cover on their own, since each of them only exercises a single migration's up() against a
 * schema it builds by hand, never the accumulated effect of the full chain on one populated
 * database. Regression coverage for the two known failure modes this command is meant to catch
 * (removing "PRAGMA foreign_keys = OFF" from Version20260801000003; dropping `id` from the
 * `INSERT ... SELECT` in Version20260923000000) was verified manually while implementing this
 * command by breaking each migration in turn, confirming this check fails with a message naming
 * the lost/desynced rows, and restoring the migration — encoding that as a permanent automated
 * test would mean shipping a real migration file in a temporarily broken state.
 */
final class MigrationDataIntegrityCheckCommandTest extends TestCase
{
    private const APP_DIR = __DIR__.'/../../..';

    public function testPassesOnTheCurrentMigrationChainAndLeavesNoTempDirectory(): void
    {
        $before = $this->tempDirs();

        $tester = new CommandTester(new MigrationDataIntegrityCheckCommand(self::APP_DIR));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('survived the full migration chain intact', $tester->getDisplay());
        $this->assertSame($before, $this->tempDirs());
    }

    /**
     * @return list<string>
     */
    private function tempDirs(): array
    {
        $dirs = glob(sys_get_temp_dir().'/animedb_migrations_data_check_[0-9a-f]*') ?: [];
        sort($dirs);

        return $dirs;
    }
}
