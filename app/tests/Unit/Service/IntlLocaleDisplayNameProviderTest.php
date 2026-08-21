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

use App\Service\IntlLocaleDisplayNameProvider;
use PHPUnit\Framework\TestCase;

final class IntlLocaleDisplayNameProviderTest extends TestCase
{
    private IntlLocaleDisplayNameProvider $provider;

    protected function setUp(): void
    {
        if (!class_exists(\Locale::class)) {
            self::markTestSkipped('ext-intl is not available in this environment.');
        }

        $this->provider = new IntlLocaleDisplayNameProvider();
    }

    public function testGetDisplayNameReturnsTheEndonymForAKnownLocale(): void
    {
        $this->assertSame('English', $this->provider->getDisplayName('en'));
    }

    public function testGetDisplayNameReturnsTheEndonymForALocaleWithARegion(): void
    {
        $this->assertSame('Deutsch (Österreich)', $this->provider->getDisplayName('de-AT'));
    }

    /**
     * ICU still resolves a made-up-but-BCP-47-shaped code to *something* rather than rejecting
     * it outright — this asserts that non-empty guess is passed through as-is, not discarded.
     */
    public function testGetDisplayNameReturnsIcuOwnGuessForAnUnrecognizedButWellFormedCode(): void
    {
        $this->assertSame('xx (YY)', $this->provider->getDisplayName('xx-YY'));
    }

    /**
     * A locale string past ICU's internal length limit makes `\Locale::getDisplayName()` return
     * `false` (a hard failure, not an empty guess) — this is the deterministic, ICU-version-stable
     * way to exercise the false/'' -> null normalization without relying on CLDR data lookup.
     */
    public function testGetDisplayNameReturnsNullWhenIcuFailsOutright(): void
    {
        $this->assertNull($this->provider->getDisplayName(str_repeat('a', 200)));
    }
}
