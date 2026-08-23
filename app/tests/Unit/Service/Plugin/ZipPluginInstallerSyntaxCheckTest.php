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
use App\Service\Plugin\ZipPluginInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises {@see ZipPluginInstaller}'s private syntax-check code (via reflection, the same idiom
 * {@see PluginCacheWarmerTest::testStagingDirectoryIsSiblingOfPluginsDir()} already uses for a
 * private method) as a real child process, through both {@see PhpCliCommand} branches. A test that
 * only asserts the built command *array* would not have caught issue #478: `PhpCliCommand::build()`
 * already produced the technically-correct-looking `[frankenphp, php-cli, -l, file]` before this
 * fix, it just did not work — `php-cli` does not parse `-l`. Only actually running the built
 * command proves the fix.
 *
 * The "frankenphp" branch is exercised against a small stand-in script (not the real FrankenPHP
 * binary, which is a ~170 MB download unavailable in a unit test) that reproduces the two
 * behaviours this class's fix depends on, both confirmed against a real FrankenPHP v1.12.4 binary
 * during development of this test: `php-cli -r <code>` runs `<code>` but leaves `$argv` undefined
 * inside it, and `php-cli` given anything else as its first argument (a flag like `-l`, or a
 * script path) fails outright rather than running it.
 */
final class ZipPluginInstallerSyntaxCheckTest extends TestCase
{
    private string $fixturesDir;
    private string $frankenphpStandIn;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir().'/anime-syntax-check-test-'.uniqid();
        mkdir($this->fixturesDir, recursive: true);

        $this->frankenphpStandIn = $this->fixturesDir.'/frankenphp';
        file_put_contents($this->frankenphpStandIn, <<<'PHP'
            #!/usr/bin/env php
            <?php
            $arguments = $argv;
            array_shift($arguments);
            if (array_shift($arguments) !== 'php-cli') {
                fwrite(STDERR, 'unsupported subcommand');
                exit(255);
            }
            if (($arguments[0] ?? null) !== '-r') {
                // Mirrors FrankenPHP's real php-cli: any leading token other than "-r" is treated
                // as a script path to open, and fails, regardless of whether it looks like a flag.
                fwrite(STDERR, sprintf("\nFatal error: Failed opening required '%s' (include_path='.:') in Unknown on line 0\n", $arguments[0] ?? ''));
                exit(255);
            }
            $code = $arguments[1] ?? '';
            unset($arguments, $argv);
            eval($code);
            PHP);
        chmod($this->frankenphpStandIn, 0o755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
    }

    /**
     * Yields a label rather than a binary path: the actual `frankenphp` stand-in path only exists
     * once {@see self::setUp()} has created it, and data provider methods run before that.
     *
     * @return iterable<string, array{string}>
     */
    public static function phpBinaryLabelProvider(): iterable
    {
        yield 'regular php' => ['regular php'];
        yield 'frankenphp' => ['frankenphp'];
    }

    #[DataProvider('phpBinaryLabelProvider')]
    #[Group('runtime-parity')]
    public function testValidFileExitsSuccessfully(string $phpBinaryLabel): void
    {
        $file = $this->fixturesDir.'/valid.php';
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nfinal class Plugin\n{\n}\n");

        $process = $this->runSyntaxCheck($this->resolveBinary($phpBinaryLabel), $file);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('', trim($process->getOutput().$process->getErrorOutput()));
    }

    #[DataProvider('phpBinaryLabelProvider')]
    #[Group('runtime-parity')]
    public function testFileWithSyntaxErrorExitsWithLineNumberInMessage(string $phpBinaryLabel): void
    {
        $file = $this->fixturesDir.'/broken.php';
        file_put_contents($file, "<?php\n\n\$x = ;\n");

        $process = $this->runSyntaxCheck($this->resolveBinary($phpBinaryLabel), $file);

        $this->assertFalse($process->isSuccessful());
        $this->assertNotSame(2, $process->getExitCode());
        $this->assertStringContainsString('on line 3', $process->getErrorOutput());
    }

    #[DataProvider('phpBinaryLabelProvider')]
    #[Group('runtime-parity')]
    public function testMissingFileIsNotReportedAsSyntaxError(string $phpBinaryLabel): void
    {
        $process = $this->runSyntaxCheck($this->resolveBinary($phpBinaryLabel), $this->fixturesDir.'/does-not-exist.php');

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringNotContainsString('on line', $process->getErrorOutput());
    }

    private function resolveBinary(string $phpBinaryLabel): string
    {
        return $phpBinaryLabel === 'frankenphp' ? $this->frankenphpStandIn : \PHP_BINARY;
    }

    private function runSyntaxCheck(string $phpBinary, string $file): Process
    {
        $code = (string) (new \ReflectionMethod(ZipPluginInstaller::class, 'syntaxCheckCode'))->invoke(null);
        $fileEnvName = (new \ReflectionClass(ZipPluginInstaller::class))->getConstant('SYNTAX_CHECK_FILE_ENV');

        $process = new Process(PhpCliCommand::forEval($phpBinary, $code), null, [$fileEnvName => $file]);
        $process->run();

        return $process;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
