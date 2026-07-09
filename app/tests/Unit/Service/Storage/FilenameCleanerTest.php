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

namespace App\Tests\Unit\Service\Storage;

use App\Service\Storage\FilenameCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenameCleanerTest extends TestCase
{
    private FilenameCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new FilenameCleaner();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideNames(): array
    {
        return [
            'folder with year and type tag' => [
                'Sailor Moon (1992) [TV]',
                'Sailor Moon',
            ],
            'folder with year range, type and quality tags' => [
                'Bleach (2004-2012) [TV] HDRip 720p',
                'Bleach',
            ],
            'dot-separated rip tags before extension' => [
                "Rybka Pon'o na Utjose.(2008).BDRip.1080p.(DVD9).[NoLimits-Team].mkv",
                "Rybka Pon'o na Utjose",
            ],
            'underscores and double-underscore separators' => [
                'Howls Moving Castle_HDRip_sub__[scarabey.org].avi',
                'Howls Moving Castle',
            ],
            'releaser tag, dual audio and checksum brackets' => [
                '[Anime Land] Mobile Suit Gundam Thunderbolt December Sky (Dual Audio) (BDRip 720p Hi10P QAACx2) [EE69A6C6].mkv',
                'Mobile Suit Gundam Thunderbolt December Sky',
            ],
            'releaser tag and checksum bracket' => [
                '[Commie] Haikyuu!! Second Season - 14 [55DED0D6].mkv',
                'Haikyuu!! Second Season - 14',
            ],
            'releaser tag and resolution bracket' => [
                '[HorribleSubs] Battery - 02 [720p].mkv',
                'Battery - 02',
            ],
            'title with punctuation and resolution bracket' => [
                '[HorribleSubs] Kono Bijutsubu ni wa Mondai ga Aru! - 03 [1080p].mkv',
                'Kono Bijutsubu ni wa Mondai ga Aru! - 03',
            ],
            'resolution and checksum brackets combined' => [
                '[Impatience] Fate Kaleid Liner Prisma Illya 3rei!! - 03 [720p][A163DBD5].mkv',
                'Fate Kaleid Liner Prisma Illya 3rei!! - 03',
            ],
            'underscored separators with multiple bracket groups' => [
                '[Kaitou]_Onara_Gorou_-_02_[720p][10bit][40F0D37C].mkv',
                'Onara Gorou - 02',
            ],
            'title ending with exclamation marks' => [
                '[Kaitou]_Ozmafia!!_-_03_[720p][10bit][CDE45C5F].mkv',
                'Ozmafia!! - 03',
            ],
            'dot-separated western release name without brackets' => [
                'Attack.on.Titan.S04E01.1080p.WEB-DL.x264.mkv',
                'Attack on Titan S04E01',
            ],
            'curly-brace bracket group' => [
                'Naruto {1080p}.mkv',
                'Naruto',
            ],
            'mixed Cyrillic and Latin title with underscores and brackets' => [
                'Ковбой_Бибоп_Cowboy_Bebop_-_01_[1080p][AAC].mkv',
                'Ковбой Бибоп Cowboy Bebop - 01',
            ],
            'unknown dot-separated extension is not stripped, dots become spaces' => [
                "Vivy.Fluorite.Eye's.Song",
                "Vivy Fluorite Eye's Song",
            ],
        ];
    }

    #[DataProvider('provideNames')]
    public function testClean(string $name, string $expected): void
    {
        $this->assertSame($expected, $this->cleaner->clean($name));
    }

    public function testCleanWithAdditionalQualityTags(): void
    {
        $cleaner = new FilenameCleaner(['MyGroupTag']);

        $this->assertSame('Some Title - 01', $cleaner->clean('Some Title - 01 MyGroupTag.mkv'));
    }
}
