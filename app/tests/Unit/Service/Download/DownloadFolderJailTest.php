<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Download;

use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\DownloadFolderJail;
use App\Service\Exception\DownloadPathOutsideJailException;
use PHPUnit\Framework\TestCase;

final class DownloadFolderJailTest extends TestCase
{
    private const string ROOT = 'C:\\Users\\bob\\Downloads';

    private string $configPath;
    private DownloadFolderJail $jail;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-downloads-jail-test-'.uniqid().'.json';
        file_put_contents($this->configPath, json_encode(['downloadsRoot' => self::ROOT]));

        $this->jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testGetRootStripsTrailingSeparator(): void
    {
        $this->assertSame(self::ROOT, $this->jail->getRoot());
    }

    public function testResolveSavePathForInfoHashBuildsALongPathPrefixedSubdirectory(): void
    {
        $path = $this->jail->resolveSavePathForInfoHash('a'.str_repeat('b', 39));

        $this->assertSame('\\\\?\\'.self::ROOT.'\\a'.str_repeat('b', 39), $path);
    }

    public function testAssertWithinRootAcceptsTheRootItself(): void
    {
        $this->assertSame(self::ROOT, $this->jail->assertWithinRoot(self::ROOT));
    }

    public function testAssertWithinRootAcceptsAPathUnderTheRoot(): void
    {
        $path = self::ROOT.'\\some-release\\video.mkv';

        $this->assertSame($path, $this->jail->assertWithinRoot($path));
    }

    public function testAssertWithinRootAcceptsALongPathPrefixedPathUnderTheRoot(): void
    {
        $path = '\\\\?\\'.self::ROOT.'\\some-release\\video.mkv';

        $this->assertSame(self::ROOT.'\\some-release\\video.mkv', $this->jail->assertWithinRoot($path));
    }

    public function testAssertWithinRootRejectsATraversalEscapingTheRoot(): void
    {
        $this->expectException(DownloadPathOutsideJailException::class);

        $this->jail->assertWithinRoot(self::ROOT.'\\..\\..\\Windows\\System32');
    }

    public function testAssertWithinRootRejectsASiblingDirectory(): void
    {
        $this->expectException(DownloadPathOutsideJailException::class);

        $this->jail->assertWithinRoot('C:\\Users\\bob\\Documents\\secret.txt');
    }

    public function testAssertWithinRootRejectsAnUnrelatedAbsolutePath(): void
    {
        $this->expectException(DownloadPathOutsideJailException::class);

        $this->jail->assertWithinRoot('D:\\other-drive\\file.mkv');
    }

    public function testAssertWithinRootAcceptsADifferentlyCasedPathUnderTheRoot(): void
    {
        // Windows paths are case-insensitive — qBittorrent/libtorrent is free to echo content_path
        // back with different segment casing than the configured downloads root.
        $path = 'c:\\users\\BOB\\downloads\\Some-Release\\video.mkv';

        $this->assertSame($path, $this->jail->assertWithinRoot($path));
    }

    public function testAssertWithinRootAcceptsTheRootItselfInADifferentCase(): void
    {
        $this->assertSame(strtolower(self::ROOT), $this->jail->assertWithinRoot(strtolower(self::ROOT)));
    }

    public function testAssertWithinRootStillRejectsADifferentlyCasedSiblingDirectory(): void
    {
        $this->expectException(DownloadPathOutsideJailException::class);

        $this->jail->assertWithinRoot('c:\\users\\bob\\DOCUMENTS\\secret.txt');
    }

    public function testToLongPathAwareIsIdempotent(): void
    {
        $once = $this->jail->toLongPathAware(self::ROOT.'\\x');
        $twice = $this->jail->toLongPathAware($once);

        $this->assertSame($once, $twice);
    }

    public function testToLongPathAwareLeavesNonWindowsPathsUntouched(): void
    {
        $this->assertSame('/tmp/downloads/x', $this->jail->toLongPathAware('/tmp/downloads/x'));
    }
}
