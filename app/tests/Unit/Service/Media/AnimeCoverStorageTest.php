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

use App\Entity\Anime;
use App\Entity\TvAnime;
use App\Service\Media\AnimeCoverStorage;
use App\Service\Media\CoverUploadException;
use App\Service\Media\ImageNormalizer;
use PHPUnit\Framework\TestCase;

final class AnimeCoverStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/cover-storage-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/media/1', 0o755, true);
        mkdir($this->root.'/media/2', 0o755, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->root.'/media/1', 0o755);
        foreach (['media/1', 'media/2'] as $dir) {
            foreach (glob($this->root.'/'.$dir.'/*') ?: [] as $file) {
                is_file($file) ? unlink($file) : null;
            }
        }
        foreach (['media/1', 'media/2', 'media', ''] as $dir) {
            @rmdir($this->root.($dir === '' ? '' : '/'.$dir));
        }
        @unlink($this->root.'/data.db');
        @unlink($this->root.'/media/1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'parent of the entry dir' => ['../2/x.webp'];
        yield 'two levels up' => ['../../data.db'];
        yield 'nested path' => ['sub/x.webp'];
        yield 'empty' => [''];
    }

    /**
     * @dataProvider unsafeNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeNames')]
    public function testReleaseIfUnusedIgnoresNamesThatAreNotBareFileNames(string $name): void
    {
        $victims = [
            $this->root.'/media/2/x.webp',
            $this->root.'/data.db',
            $this->root.'/media/1/sub/x.webp',
        ];
        mkdir($this->root.'/media/1/sub');
        foreach ($victims as $victim) {
            file_put_contents($victim, 'keep');
        }

        $this->storage()->releaseIfUnused($this->anime(1), $name);

        foreach ($victims as $victim) {
            self::assertFileExists($victim);
        }
        foreach ($victims as $victim) {
            unlink($victim);
        }
        rmdir($this->root.'/media/1/sub');
    }

    public function testReleaseIfUnusedDeletesAnUnreferencedFile(): void
    {
        file_put_contents($this->root.'/media/1/old.webp', 'x');

        $this->storage()->releaseIfUnused($this->anime(1), 'old.webp');

        self::assertFileDoesNotExist($this->root.'/media/1/old.webp');
    }

    public function testReleaseIfUnusedKeepsTheCurrentCover(): void
    {
        file_put_contents($this->root.'/media/1/cur.webp', 'x');
        $anime = $this->anime(1)->setCover('cur.webp');

        $this->storage()->releaseIfUnused($anime, 'cur.webp');

        self::assertFileExists($this->root.'/media/1/cur.webp');
    }

    public function testStoreFailsWhenTheEntryDirectoryCannotBeCreated(): void
    {
        rmdir($this->root.'/media/1');
        file_put_contents($this->root.'/media/1', 'not a directory');
        $anime = $this->anime(1)->setCover('before.webp');

        try {
            $this->storage()->store($anime, 'webp-bytes');
            self::fail('Expected CoverUploadException.');
        } catch (CoverUploadException $e) {
            self::assertSame('anime_edit.error_cover_save', $e->errorKey);
        }

        self::assertSame('before.webp', $anime->getCover());
    }

    public function testStoreFailsAndLeavesNoTempFileWhenTheDirectoryIsNotWritable(): void
    {
        chmod($this->root.'/media/1', 0o555);
        if (is_writable($this->root.'/media/1')) {
            self::markTestSkipped('The directory stays writable (running as root).');
        }
        $anime = $this->anime(1)->setCover('before.webp');
        $tmpBefore = glob(sys_get_temp_dir().'/tmp-*') ?: [];

        try {
            $this->storage()->store($anime, 'webp-bytes');
            self::fail('Expected CoverUploadException.');
        } catch (CoverUploadException $e) {
            self::assertSame('anime_edit.error_cover_save', $e->errorKey);
        }

        self::assertSame([], glob($this->root.'/media/1/*'));
        // The tempnam() fallback file in the system temp dir must not be left behind either.
        self::assertSame([], array_diff(glob(sys_get_temp_dir().'/tmp-*') ?: [], $tmpBefore));
        self::assertSame('before.webp', $anime->getCover());
    }

    private function storage(): AnimeCoverStorage
    {
        return new AnimeCoverStorage(new ImageNormalizer(), $this->root.'/media');
    }

    private function anime(int $id): Anime
    {
        $anime = new TvAnime();
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, $id);

        return $anime;
    }
}
