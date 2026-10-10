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

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginCacheDirectory;
use PHPUnit\Framework\TestCase;

final class PluginCacheDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/anime-plugin-cache-dir-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPathCreatesTheMissingDirectoryAndReturnsAnExistingWritableAbsolutePath(): void
    {
        $this->assertDirectoryDoesNotExist($this->root);

        $path = (new PluginCacheDirectory(new PluginId('some-plugin'), $this->root.'/plugin-cache'))->path();

        $this->assertSame($this->root.'/plugin-cache/some-plugin', $path);
        $this->assertSame('/', $path[0]);
        $this->assertDirectoryExists($path);
        $this->assertDirectoryIsWritable($path);
    }

    public function testPathIsStableAndKeepsExistingContent(): void
    {
        $directory = new PluginCacheDirectory(new PluginId('some-plugin'), $this->root);
        file_put_contents($directory->path().'/dump.bin', 'x');

        $this->assertSame($directory->path(), $directory->path());
        $this->assertFileExists($directory->path().'/dump.bin');
    }

    public function testDifferentPluginsGetDifferentDirectories(): void
    {
        $first = (new PluginCacheDirectory(new PluginId('plugin-one'), $this->root))->path();
        $second = (new PluginCacheDirectory(new PluginId('plugin-two'), $this->root))->path();

        $this->assertNotSame($first, $second);
    }

    public function testPathHasNoTrailingSeparator(): void
    {
        $path = (new PluginCacheDirectory(new PluginId('some-plugin'), $this->root.'/'))->path();

        $this->assertStringEndsWith('some-plugin', $path);
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
