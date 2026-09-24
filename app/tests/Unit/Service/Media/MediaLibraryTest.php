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

namespace App\Tests\Unit\Service\Media;

use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Service\Media\MediaHandleRegistry;
use App\Service\Media\MediaLibrary;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MediaLibraryTest extends TestCase
{
    private string $base;
    private string $root;
    private MediaHandleRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/media-library-test-'.uniqid();
        $this->root = $this->base.'/root';
        mkdir($this->root, recursive: true);
        $this->registry = new MediaHandleRegistry();
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    }

    private function touchFile(string $relative, string $content = 'x'): void
    {
        $path = $this->root.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), recursive: true);
        }
        file_put_contents($path, $content);
    }

    /** @return MediaFile[] */
    private function list(?string $storagePath, ?string $rootPath = null, bool $withStorage = true, bool $found = true): array
    {
        $anime = new TvAnime();
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, 7);
        $anime->setStoragePath($storagePath);

        if ($withStorage) {
            $storage = new Storage('S', $rootPath ?? $this->root, StorageType::Folder);
            (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 3);
            $anime->setStorage($storage);
        }

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($found ? $anime : null);

        return (new MediaLibrary($em, new StorageMarkerService($em), $this->registry))->listFiles(new AnimeId(7));
    }

    /**
     * @param MediaFile[] $files
     *
     * @return string[]
     */
    private static function paths(array $files): array
    {
        return array_map(static fn (MediaFile $f): string => $f->relativePath, $files);
    }

    public function testNothingToListReturnsEmpty(): void
    {
        $this->touchFile('a/01.mkv');

        self::assertSame([], $this->list('a', withStorage: false));
        self::assertSame([], $this->list(null));
        self::assertSame([], $this->list(''));
        self::assertSame([], $this->list('a', found: false));
    }

    public function testFileTargetReturnsOnlyThatFile(): void
    {
        $this->touchFile('Naruto/01.mkv');
        $this->touchFile('Naruto/02.mkv');
        $this->touchFile('other.mkv');

        self::assertSame(['other.mkv'], self::paths($this->list('other.mkv')));
        $files = $this->list('Naruto/01.mkv');
        self::assertSame(['01.mkv'], self::paths($files));
        self::assertSame(1, $files[0]->sizeBytes);
    }

    public function testRootItselfIsNotBound(): void
    {
        $this->touchFile('a/01.mkv');
        $this->touchFile('01.mkv');

        self::assertSame([], $this->list('.'));
        self::assertSame([], $this->list('./'));
    }

    public function testTopLevelMediaStopsDescent(): void
    {
        $this->touchFile('a/01.mkv');
        $this->touchFile('a/sub/02.mkv');

        self::assertSame(['01.mkv'], self::paths($this->list('a')));
    }

    public function testEmptyLevelDescendsIntoAllSubfolders(): void
    {
        $this->touchFile('a/readme.txt');
        $this->touchFile('a/s1/01.mkv');
        $this->touchFile('a/s2/02.mkv');
        $this->touchFile('a/s2/deeper/03.mkv');

        self::assertSame(['s1/01.mkv', 's2/02.mkv'], self::paths($this->list('a')));
    }

    public function testDepthLimit(): void
    {
        $this->touchFile('a/1/2/3/4/5/ok.mkv');
        self::assertSame(['1/2/3/4/5/ok.mkv'], self::paths($this->list('a')));

        $this->remove($this->root.'/a');
        $this->touchFile('a/1/2/3/4/5/6/deep.mkv');
        self::assertSame([], $this->list('a'));
    }

    public function testDirectoryCeiling(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            mkdir($this->root.'/a/d'.sprintf('%03d', $i), recursive: true);
        }
        $this->touchFile('a/d150/01.mkv');
        self::assertSame(['d150/01.mkv'], self::paths($this->list('a')));

        // 200 directories are consumed by the empty top level and its subfolders' siblings
        mkdir($this->root.'/b/x', recursive: true);
        for ($i = 0; $i < 200; ++$i) {
            mkdir($this->root.'/b/x/e'.sprintf('%03d', $i), recursive: true);
        }
        $this->touchFile('b/x/e199/01.mkv');
        self::assertSame([], $this->list('b'));
    }

    public function testAllThreeSetsCount(): void
    {
        $this->touchFile('a/01.MKV');
        $this->touchFile('a/01.flac');
        $this->touchFile('a/01.ass');
        $this->touchFile('a/cover.jpg');

        self::assertSame(['01.ass', '01.flac', '01.MKV'], self::paths($this->list('a')));
    }

    public function testSubtitlesAloneStopDescent(): void
    {
        $this->touchFile('a/01.ass');
        $this->touchFile('a/s/01.mkv');

        self::assertSame(['01.ass'], self::paths($this->list('a')));
    }

    public function testRelativePathAndNaturalSort(): void
    {
        $this->touchFile('a/e/10.mkv');
        $this->touchFile('a/e/9.mkv');
        $this->touchFile('a/e/2.mkv');

        $files = $this->list('a');
        self::assertSame(['e/2.mkv', 'e/9.mkv', 'e/10.mkv'], self::paths($files));
        self::assertSame('9.mkv', $files[1]->name);
    }

    public function testSymlinkOutsideAndDotDotAreSkipped(): void
    {
        mkdir($this->base.'/outside');
        file_put_contents($this->base.'/outside/leak.mkv', 'x');
        $this->touchFile('a/01.mkv');
        $this->touchFile('other/secret.mkv');
        symlink($this->base.'/outside/leak.mkv', $this->root.'/a/link.mkv');
        symlink($this->base.'/outside', $this->root.'/a/linkdir');

        self::assertSame(['01.mkv'], self::paths($this->list('a')));
        // A '..' target that resolves outside the storage root is rejected.
        self::assertSame([], $this->list('../outside'));
        self::assertSame([], $this->list('a/../../outside'));
    }

    public function testSymlinkedTargetOutsideRootIsRejected(): void
    {
        mkdir($this->base.'/outside');
        file_put_contents($this->base.'/outside/leak.mkv', 'x');
        symlink($this->base.'/outside', $this->root.'/a');

        self::assertSame([], $this->list('a'));
    }

    public function testUnreadableRootThrowsButMissingTargetIsEmpty(): void
    {
        self::assertSame([], $this->list('missing'));

        $this->expectException(StorageUnavailableException::class);
        $this->list('a', $this->base.'/gone');
    }

    public function testForeignMarkerThrowsAndMissingMarkerIsFine(): void
    {
        $this->touchFile('a/01.mkv');
        self::assertSame(['01.mkv'], self::paths($this->list('a')));

        file_put_contents($this->root.'/desktop.ini', "[AnimeDB]\nid=3\n");
        self::assertSame(['01.mkv'], self::paths($this->list('a')));

        file_put_contents($this->root.'/desktop.ini', "[AnimeDB]\nid=99\n");
        $this->expectException(StorageUnavailableException::class);
        $this->list('a');
    }

    public function testIssuedHandlesAreRegisteredByObject(): void
    {
        $this->touchFile('a/01.mkv');
        $files = $this->list('a');

        $handle = $this->registry->get($files[0]);
        self::assertNotNull($handle);
        self::assertSame(7, $handle->animeId);
        self::assertSame(realpath($this->root).'/a/01.mkv', $handle->absolutePath);

        $lookalike = new MediaFile($files[0]->name, $files[0]->relativePath, $files[0]->sizeBytes, $files[0]->modifiedAt);
        self::assertNull($this->registry->get($lookalike));
    }
}
