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

namespace App\Tests\Unit\Service\Plugin;

use App\Service\Plugin\PhpCliCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpCliCommandTest extends TestCase
{
    public function testRegularPhpBinaryIsInvokedDirectly(): void
    {
        $command = PhpCliCommand::build('/usr/bin/php', '-l', '/tmp/file.php');

        $this->assertSame(['/usr/bin/php', '-l', '/tmp/file.php'], $command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frankenphpBinaryProvider(): iterable
    {
        yield 'unix binary name' => ['frankenphp'];
        yield 'windows binary name' => ['frankenphp.exe'];
        yield 'windows binary name, mixed case' => ['FrankenPHP.EXE'];
        // basename() only splits on the current OS's directory separator(s), so a full path is
        // only representative when written with the separator this test actually runs under.
        yield 'full path' => [\DIRECTORY_SEPARATOR === '\\' ? 'C:\\Program Files\\AnimeDB\\bin\\frankenphp.exe' : '/opt/animedb/bin/frankenphp'];
    }

    #[DataProvider('frankenphpBinaryProvider')]
    public function testFrankenphpBinaryIsInvokedViaPhpCliSubcommand(string $phpBinary): void
    {
        $command = PhpCliCommand::build($phpBinary, '-l', '/tmp/file.php');

        $this->assertSame([$phpBinary, 'php-cli', '-l', '/tmp/file.php'], $command);
    }

    public function testBuildsCommandWithoutAdditionalArguments(): void
    {
        $this->assertSame(['/usr/bin/php'], PhpCliCommand::build('/usr/bin/php'));
        $this->assertSame(['frankenphp', 'php-cli'], PhpCliCommand::build('frankenphp'));
    }
}
