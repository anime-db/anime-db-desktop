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

use App\Service\Plugin\Exception\PluginCacheWarmupException;
use App\Service\Plugin\PluginCacheWarmer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PluginCacheWarmerTest extends TestCase
{
    /** app/, i.e. this test file's project root — a real, working `bin/console`. */
    private string $realProjectDir;

    private string $rootDir;
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->realProjectDir = \dirname(__DIR__, 4);

        $this->rootDir = sys_get_temp_dir().'/anime-cache-warmer-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testWarmUpSucceedsAndLeavesNoTemporaryDirectoryBehind(): void
    {
        $warmer = new PluginCacheWarmer($this->pluginsDir, $this->realProjectDir, new NullLogger());

        $warmer->warmUp();

        $this->assertNoLeftoverTempDirectories();
    }

    public function testWarmUpThrowsWithProcessOutputWhenConsoleScriptDoesNotExist(): void
    {
        $brokenProjectDir = $this->rootDir.'/no-console-here';
        mkdir($brokenProjectDir, recursive: true);

        $warmer = new PluginCacheWarmer($this->pluginsDir, $brokenProjectDir, new NullLogger());

        try {
            $warmer->warmUp();
            $this->fail('Expected PluginCacheWarmupException to be thrown.');
        } catch (PluginCacheWarmupException $exception) {
            $this->assertStringContainsString('console', $exception->getMessage());
        } finally {
            $this->assertNoLeftoverTempDirectories();
        }
    }

    public function testStagingDirectoryIsSiblingOfPluginsDir(): void
    {
        $warmer = new PluginCacheWarmer($this->pluginsDir, $this->realProjectDir, new NullLogger());

        $method = new \ReflectionMethod($warmer, 'stagingRootDir');

        $this->assertSame($this->rootDir.'/.plugin-install-tmp', $method->invoke($warmer));
    }

    private function assertNoLeftoverTempDirectories(): void
    {
        $stagingRoot = $this->rootDir.'/.plugin-install-tmp';
        $entries = is_dir($stagingRoot) ? scandir($stagingRoot) : [];
        $leftovers = array_values(array_diff($entries === false ? [] : $entries, ['.', '..']));

        $this->assertSame([], $leftovers);
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
