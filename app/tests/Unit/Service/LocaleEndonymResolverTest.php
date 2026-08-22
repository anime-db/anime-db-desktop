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

use App\Service\LocaleDisplayNameProvider;
use App\Service\LocaleEndonymResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LocaleEndonymResolverTest extends TestCase
{
    public function testResolveReturnsTheProvidersEndonymForAKnownLocale(): void
    {
        $provider = $this->createStub(LocaleDisplayNameProvider::class);
        $provider->method('getDisplayName')->willReturn('Deutsch');

        $resolver = new LocaleEndonymResolver($provider, new NullLogger());

        $this->assertSame('Deutsch', $resolver->resolve('de'));
    }

    public function testResolveReturnsTheProvidersEndonymForALocaleWithARegion(): void
    {
        $provider = $this->createStub(LocaleDisplayNameProvider::class);
        $provider->method('getDisplayName')->willReturn('Deutsch (Österreich)');

        $resolver = new LocaleEndonymResolver($provider, new NullLogger());

        $this->assertSame('Deutsch (Österreich)', $resolver->resolve('de-AT'));
    }

    /**
     * ICU spells some endonyms lower-case by the target language's own orthography (`русский`),
     * but the switcher lists several languages side by side and needs a uniform case — see
     * {@see LocaleEndonymResolver} for why capitalization belongs here rather than in the
     * provider.
     */
    public function testResolveCapitalizesTheFirstLetterOfTheProvidersEndonym(): void
    {
        $provider = $this->createStub(LocaleDisplayNameProvider::class);
        $provider->method('getDisplayName')->willReturn('русский');

        $resolver = new LocaleEndonymResolver($provider, new NullLogger());

        $this->assertSame('Русский', $resolver->resolve('ru'));
    }

    /**
     * The raw locale code fallback is not an endonym, so it must not be capitalized the way a
     * resolved endonym is — `De` would read worse than `de`.
     */
    public function testResolveFallsBackToTheRawLocaleCodeUncapitalizedWhenTheProviderReturnsNull(): void
    {
        $provider = $this->createStub(LocaleDisplayNameProvider::class);
        $provider->method('getDisplayName')->willReturn(null);

        $resolver = new LocaleEndonymResolver($provider, new NullLogger());

        $this->assertSame('de', $resolver->resolve('de'));
    }

    /**
     * Simulates ext-intl being unavailable, or ICU failing to resolve the code at all — both
     * surface identically through {@see LocaleDisplayNameProvider}: null. This is the scenario
     * the settings page must not turn into a fatal error for.
     */
    public function testResolveFallsBackToTheRawLocaleCodeWhenTheProviderReturnsNull(): void
    {
        $provider = $this->createStub(LocaleDisplayNameProvider::class);
        $provider->method('getDisplayName')->willReturn(null);

        $resolver = new LocaleEndonymResolver($provider, new NullLogger());

        $this->assertSame('xx', $resolver->resolve('xx'));
    }

    public function testResolveLogsAWarningWhenFallingBackToTheRawLocaleCode(): void
    {
        $provider = $this->createStub(LocaleDisplayNameProvider::class);
        $provider->method('getDisplayName')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('locale endonym'),
            $this->arrayHasKey('locale'),
        );

        $resolver = new LocaleEndonymResolver($provider, $logger);
        $resolver->resolve('xx');
    }
}
