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

use App\Service\Plugin\Exception\PluginDirectoryRemovalException;
use App\Service\Plugin\PluginDirectoryRemover;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PluginDirectoryRemoverTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-directory-remover-test-'.uniqid();
        mkdir($this->rootDir, recursive: true);
    }

    protected function tearDown(): void
    {
        // A test below may leave a directory read-only; restore write permission first so
        // cleanup can actually delete it.
        $this->restoreWritePermissions($this->rootDir);
        $this->removeDirectory($this->rootDir);
    }

    public function testRemoveDeletesNestedDirectoriesAndFiles(): void
    {
        mkdir($this->rootDir.'/plugin/nested', recursive: true);
        file_put_contents($this->rootDir.'/plugin/manifest.json', '{}');
        file_put_contents($this->rootDir.'/plugin/nested/file.txt', 'content');

        PluginDirectoryRemover::remove($this->rootDir.'/plugin');

        $this->assertDirectoryDoesNotExist($this->rootDir.'/plugin');
    }

    public function testRemoveIsANoOpWhenTheDirectoryDoesNotExist(): void
    {
        $this->expectNotToPerformAssertions();

        PluginDirectoryRemover::remove($this->rootDir.'/does-not-exist');
    }

    /**
     * Regression test for issue #420 defect D: the old removeDirectory() implementations in
     * ZipPluginInstaller/PluginRemover ignored unlink()/rmdir() return values, so a file that
     * could not actually be deleted (e.g. locked on Windows) silently left a half-removed plugin
     * directory behind — with manifest.json gone but other files still present, or vice versa —
     * for the next reconcile() to potentially misread as a valid (or invalid, but different)
     * plugin. This simulates an undeletable file via a read-only parent directory (unlink() needs
     * write permission on the *containing* directory, not the file itself) and asserts the
     * failure surfaces as {@see PluginDirectoryRemovalException} instead of being swallowed.
     */
    #[Group('runtime-parity')]
    public function testRemoveThrowsWhenAFileCannotBeDeleted(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod-based permission simulation only applies on POSIX filesystems.');
        }

        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Running as root bypasses filesystem permission checks.');
        }

        $pluginDir = $this->rootDir.'/plugin';
        mkdir($pluginDir, recursive: true);
        file_put_contents($pluginDir.'/manifest.json', '{}');
        chmod($pluginDir, 0o500);

        try {
            $this->expectException(PluginDirectoryRemovalException::class);
            PluginDirectoryRemover::remove($pluginDir);
        } finally {
            $this->restoreWritePermissions($this->rootDir);
        }
    }

    private function restoreWritePermissions(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        chmod($dir, 0o700);

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->restoreWritePermissions($path);
            }
        }
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
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
