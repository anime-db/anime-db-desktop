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

namespace App\Service;

/**
 * Abstracts `ext-intl`'s `\Locale::getDisplayName()` behind an interface, the same shape as
 * {@see Download\FreeSpaceProvider} for the same reason: whether the extension is
 * loaded at all cannot be flipped from a test, so {@see LocaleEndonymResolver} depends on this
 * instead and is unit-testable — including the "extension unavailable" path — without touching
 * the actual extension.
 */
interface LocaleDisplayNameProvider
{
    /**
     * Returns the given locale's name for itself (its endonym), or null when it could not be
     * determined — either the extension backing this provider is unavailable, or it failed to
     * produce a name for the given locale code.
     */
    public function getDisplayName(string $locale): ?string;
}
