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

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\AutorunInfReader;
use App\Service\Storage\StorageNameHinter;
use App\Service\Storage\VolumeInfo;
use App\Service\Storage\VolumeProbeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageNameHinterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/name-hinter-'.uniqid();
        mkdir($this->root.'/D', recursive: true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/D/autorun.inf');
        @rmdir($this->root.'/D');
        @rmdir($this->root);
    }

    private function probe(?string $label, int &$calls = 0): VolumeProbeInterface
    {
        return new class($label, $calls) implements VolumeProbeInterface {
            public function __construct(private readonly ?string $label, private int &$calls)
            {
            }

            public function probe(string $letter): VolumeInfo
            {
                ++$this->calls;

                return new VolumeInfo($this->label);
            }
        };
    }

    private function hinter(VolumeProbeInterface $probe): StorageNameHinter
    {
        return new StorageNameHinter(new AutorunInfReader(), $probe, $this->root.'/%s/');
    }

    public function testDriveRootUsesAutorunLabelBeforeVolumeLabel(): void
    {
        file_put_contents($this->root.'/D/autorun.inf', "\xFF\xFE".mb_convert_encoding("[autorun]\r\nlabel=Disk\r\n", 'UTF-16LE', 'UTF-8'));

        $this->assertSame('Disk', $this->hinter($this->probe('Volume'))->hint('D:\\'));
    }

    public function testDriveRootFallsBackToVolumeLabel(): void
    {
        $this->assertSame('Volume', $this->hinter($this->probe('Volume'))->hint('D:'));
    }

    public function testEmptyVolumeLabelGivesNull(): void
    {
        $this->assertNull($this->hinter($this->probe(''))->hint('D:\\'));
        $this->assertNull($this->hinter($this->probe(null))->hint('D:\\'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function stringPaths(): iterable
    {
        yield 'folder' => ['D:\\Anime\\Series', 'Series'];
        yield 'trailing separator' => ['D:\\Anime\\', 'Anime'];
        yield 'posix folder' => ['/media/anime', 'anime'];
        yield 'share root' => ['\\\\srv\\share', 'share'];
        yield 'share root with separator' => ['\\\\srv\\share\\', 'share'];
        yield 'share folder' => ['\\\\srv\\share\\dir', 'dir'];
        yield 'host only' => ['\\\\srv', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('stringPaths')]
    public function testFoldersAndSharesAreParsedAsStringsWithoutProbe(string $path, ?string $expected): void
    {
        $calls = 0;

        $this->assertSame($expected, $this->hinter($this->probe('Volume', $calls))->hint($path));
        $this->assertSame(0, $calls, 'Only a drive root may reach the volume probe.');
    }
}
