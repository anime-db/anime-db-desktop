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

use App\Command\I18nCoverageSyncCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class I18nCoverageSyncCommandTest extends TestCase
{
    private string $dir;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gh-stub-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/gh', "#!/bin/sh\ncat \"$(dirname \"\$0\")/response.json\"\n");
        chmod($this->dir.'/gh', 0o755);
        file_put_contents($this->dir.'/response.json', '[{"number": 1, "body": "a"}, {"number": 2, "body": "b"}]');
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH='.$this->dir.':'.$this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testAmbiguousIssueStateFailsWithMessageInsteadOfThrowing(): void
    {
        $tester = new CommandTester(new I18nCoverageSyncCommand(sys_get_temp_dir()));

        $exitCode = $tester->execute(['plugin' => 'animedb-shikimori', '--dry-run' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('CANNOT CHECK animedb-shikimori', $tester->getDisplay());
        self::assertStringContainsString('More than one open issue', $tester->getDisplay());
    }
}
