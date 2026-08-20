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

namespace App\Tests\Unit\Service;

use App\Service\LocaleDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleDirectionTest extends TestCase
{
    private LocaleDirection $localeDirection;

    protected function setUp(): void
    {
        $this->localeDirection = new LocaleDirection();
    }

    #[DataProvider('provideLocales')]
    public function testResolve(?string $locale, string $expected): void
    {
        $this->assertSame($expected, $this->localeDirection->resolve($locale));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string}>
     */
    public static function provideLocales(): iterable
    {
        yield 'Arabic' => ['ar', 'rtl'];
        yield 'Arabic with region (BCP 47)' => ['ar-EG', 'rtl'];
        yield 'Hebrew' => ['he', 'rtl'];
        yield 'Persian' => ['fa', 'rtl'];
        yield 'Urdu' => ['ur', 'rtl'];

        yield 'Russian' => ['ru', 'ltr'];
        yield 'English' => ['en', 'ltr'];
        yield 'German' => ['de', 'ltr'];
        yield 'Japanese' => ['ja', 'ltr'];
        yield 'unknown locale code' => ['xx', 'ltr'];
        yield 'empty string' => ['', 'ltr'];
        yield 'null' => [null, 'ltr'];
    }
}
