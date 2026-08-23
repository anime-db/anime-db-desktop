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

use App\Service\LocaleEndonymResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LocaleEndonymResolverTest extends TestCase
{
    #[Group('runtime-parity')]
    public function testResolveReturnsTheEndonymForAKnownLocale(): void
    {
        $resolver = new LocaleEndonymResolver(new NullLogger());

        $this->assertSame('English', $resolver->resolve('en'));
    }

    #[Group('runtime-parity')]
    public function testResolveReturnsTheEndonymForALocaleWithARegion(): void
    {
        $resolver = new LocaleEndonymResolver(new NullLogger());

        $this->assertSame('Deutsch (Österreich)', $resolver->resolve('de-AT'));
    }

    /**
     * ICU spells some endonyms lower-case by the target language's own orthography (`русский`),
     * but the switcher lists several languages side by side and needs a uniform case — see
     * {@see LocaleEndonymResolver} for why capitalization belongs here rather than being relied
     * upon from ICU's own output.
     */
    #[Group('runtime-parity')]
    public function testResolveCapitalizesTheFirstLetterOfTheEndonym(): void
    {
        $resolver = new LocaleEndonymResolver(new NullLogger());

        $this->assertSame('Русский', $resolver->resolve('ru'));
    }

    /**
     * A locale string past ICU's internal length limit makes `\Locale::getDisplayName()` return
     * `false` (a hard failure, not an empty guess) — this is the deterministic, ICU-version-stable
     * way to exercise the fallback without relying on CLDR data lookup.
     *
     * The raw locale code fallback is not an endonym, so it must not be capitalized the way a
     * resolved endonym is — `De` would read worse than `de`.
     */
    public function testResolveFallsBackToTheRawLocaleCodeUncapitalizedWhenIcuFailsOutright(): void
    {
        $resolver = new LocaleEndonymResolver(new NullLogger());

        $locale = str_repeat('a', 200);

        $this->assertSame($locale, $resolver->resolve($locale));
    }

    public function testResolveLogsAWarningWhenFallingBackToTheRawLocaleCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('locale endonym'),
            $this->arrayHasKey('locale'),
        );

        $resolver = new LocaleEndonymResolver($logger);
        $resolver->resolve(str_repeat('a', 200));
    }
}
