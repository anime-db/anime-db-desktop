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
    public function testRegularPhpBinaryScriptIsInvokedDirectly(): void
    {
        $command = PhpCliCommand::forScript('/usr/bin/php', '/app/bin/console', 'cache:warmup');

        $this->assertSame(['/usr/bin/php', '/app/bin/console', 'cache:warmup'], $command);
    }

    public function testRegularPhpBinaryEvalIsInvokedDirectly(): void
    {
        $command = PhpCliCommand::forEval('/usr/bin/php', 'echo PHP_VERSION;');

        $this->assertSame(['/usr/bin/php', '-r', 'echo PHP_VERSION;'], $command);
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
    public function testFrankenphpBinaryScriptIsInvokedViaPhpCliSubcommand(string $phpBinary): void
    {
        $command = PhpCliCommand::forScript($phpBinary, '/app/bin/console', 'cache:warmup');

        $this->assertSame([$phpBinary, 'php-cli', '/app/bin/console', 'cache:warmup'], $command);
    }

    #[DataProvider('frankenphpBinaryProvider')]
    public function testFrankenphpBinaryEvalIsInvokedViaPhpCliSubcommand(string $phpBinary): void
    {
        $command = PhpCliCommand::forEval($phpBinary, 'echo PHP_VERSION;');

        $this->assertSame([$phpBinary, 'php-cli', '-r', 'echo PHP_VERSION;'], $command);
    }

    public function testForScriptWithoutAdditionalArguments(): void
    {
        $this->assertSame(['/usr/bin/php', '/app/bin/console'], PhpCliCommand::forScript('/usr/bin/php', '/app/bin/console'));
        $this->assertSame(['frankenphp', 'php-cli', '/app/bin/console'], PhpCliCommand::forScript('frankenphp', '/app/bin/console'));
    }
}
