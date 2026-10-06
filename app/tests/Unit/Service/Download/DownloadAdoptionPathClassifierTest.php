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

namespace App\Tests\Unit\Service\Download;

use App\Service\Download\DownloadAdoptionBranch;
use App\Service\Download\DownloadAdoptionPathClassifier;
use App\Service\Download\DownloadAdoptionRefusedException;
use App\Service\Download\DownloadFolderJail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure string-level tests: no files, no database, no markers.
 */
final class DownloadAdoptionPathClassifierTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OTHER_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const array STORAGES = [
        ['id' => 1, 'root' => 'E:\Anime'],
        ['id' => 2, 'root' => 'E:\Anime\Sub'],
        ['id' => 3, 'root' => 'D:\Other'],
    ];

    private function classifier(): DownloadAdoptionPathClassifier
    {
        return new DownloadAdoptionPathClassifier(new DownloadFolderJail());
    }

    /**
     * @return iterable<string, array{string, bool, int, DownloadAdoptionBranch, string}>
     */
    public static function acceptedProvider(): iterable
    {
        yield 'incoming directory' => ['E:\Anime\.anime-db\incoming\\'.self::HASH.'\Release', false, 1, DownloadAdoptionBranch::Incoming, 'Release'];
        yield 'incoming single file' => ['E:\Anime\.anime-db\incoming\\'.self::HASH.'\Movie.mkv', true, 1, DownloadAdoptionBranch::Incoming, 'Movie'];
        yield 'root directory' => ['E:\Anime\Release', false, 1, DownloadAdoptionBranch::Root, 'Release'];
        yield 'root single file' => ['E:\Anime\Release\Movie.mkv', true, 1, DownloadAdoptionBranch::Root, 'Release'];
        yield 'nested storage wins' => ['E:\Anime\Sub\Release', false, 2, DownloadAdoptionBranch::Root, 'Release'];
        yield 'nested storage incoming' => ['E:\Anime\Sub\.anime-db\incoming\\'.self::HASH.'\Release', false, 2, DownloadAdoptionBranch::Incoming, 'Release'];
        yield 'other drive, forward slashes' => ['D:/Other/Release', false, 3, DownloadAdoptionBranch::Root, 'Release'];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsTheThreeForms(string $path, bool $single, int $storageId, DownloadAdoptionBranch $branch, string $name): void
    {
        $plan = $this->classifier()->classify($path, self::HASH, self::STORAGES, $single);

        $this->assertSame($storageId, $plan->storageId);
        $this->assertSame($branch, $plan->branch);
        $this->assertSame($name, $plan->name);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'storage root' => ['E:\Anime\Sub', false, 'download_adopt.error_path_is_root'];
        yield 'bare incoming' => ['E:\Anime\.anime-db\incoming\\'.self::HASH, false, 'download_adopt.error_bare_incoming'];
        yield 'foreign incoming' => ['E:\Anime\.anime-db\incoming\\'.self::OTHER_HASH.'\Release', false, 'download_adopt.error_foreign_incoming'];
        yield 'hidden top-level' => ['E:\Anime\.hidden\Release', false, 'download_adopt.error_hidden_segment'];
        yield 'hidden name' => ['E:\Anime\.Release', false, 'download_adopt.error_hidden_segment'];
        yield 'anime-db not incoming' => ['E:\Anime\.anime-db\Release', false, 'download_adopt.error_hidden_segment'];
        yield 'anime-db not first' => ['E:\Anime\Release\.anime-db\incoming\\'.self::HASH.'\X', true, 'download_adopt.error_hidden_segment'];
        yield 'hidden under incoming' => ['E:\Anime\.anime-db\incoming\\'.self::HASH.'\.Release', false, 'download_adopt.error_hidden_segment'];
        yield 'hash folder' => ['E:\Anime\\'.self::OTHER_HASH, false, 'download_adopt.error_hash_folder'];
        yield 'multi-file too deep' => ['E:\Anime\Release\Extra', false, 'download_adopt.error_unexpected_depth'];
        yield 'single file too deep' => ['E:\Anime\Release\Extra\Movie.mkv', true, 'download_adopt.error_unexpected_depth'];
        yield 'single file bare in root' => ['E:\Anime\Movie.mkv', true, 'download_adopt.error_unexpected_depth'];
        yield 'incoming too deep' => ['E:\Anime\.anime-db\incoming\\'.self::HASH.'\Release\Extra', false, 'download_adopt.error_unexpected_depth'];
        yield 'outside every storage' => ['F:\Elsewhere\Release', false, 'download_adopt.error_no_storage'];
        yield 'sibling prefix of a root' => ['E:\AnimeExtra\Release', false, 'download_adopt.error_no_storage'];
    }

    #[DataProvider('refusedProvider')]
    public function testRefusesWithItsOwnReason(string $path, bool $single, string $key): void
    {
        try {
            $this->classifier()->classify($path, self::HASH, self::STORAGES, $single);
            $this->fail('Expected a refusal.');
        } catch (DownloadAdoptionRefusedException $e) {
            $this->assertSame($key, $e->translationKey);
        }
    }
}
