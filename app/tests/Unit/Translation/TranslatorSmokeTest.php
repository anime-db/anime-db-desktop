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

namespace App\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslatorSmokeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideKeys(): iterable
    {
        yield 'genre' => ['genre.romance', 'Romance'];
        yield 'anime_type' => ['anime_type.tv', 'TV Series'];
        yield 'watch_status' => ['watch_status.watching', 'Watching'];
        yield 'anime_name_type' => ['anime_name_type.original', 'Original'];
        yield 'storage_type' => ['storage_type.folder', 'Folder'];
        yield 'production_status' => ['production_status.ongoing', 'Ongoing'];
    }

    #[DataProvider('provideKeys')]
    public function testTranslatorLoadsEnglishCatalog(string $key, string $expected): void
    {
        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $this->assertSame($expected, $translator->trans($key, locale: 'en'));
    }

    public function testTranslatorLoadsRussianCatalog(): void
    {
        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $this->assertSame('Романтика', $translator->trans('genre.romance', locale: 'ru'));
    }

    public function testTranslatorFallsBackToEnglishForUnknownLocale(): void
    {
        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $this->assertSame('Ongoing', $translator->trans('production_status.ongoing', locale: 'fr'));
    }
}
