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

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Values such as an anime title or a storage path come from sources written in Latin or
 * Japanese script and get interpolated into interface strings (issue #450). Inside an RTL
 * phrase, an unisolated value can reorder adjacent characters — most visibly brackets, colons
 * and digits. `bidi_isolate()` wraps a value in Unicode directional isolate marks (FSI/PDI)
 * before it reaches a %placeholder% substitution, e.g. a `trans()` parameter or a JS-string
 * argument, where wrapping with a `<bdi>` element is not available.
 */
final class BidiIsolationExtension extends AbstractExtension
{
    private const ISOLATE_START = "\u{2068}"; // FIRST STRONG ISOLATE
    private const ISOLATE_END = "\u{2069}";   // POP DIRECTIONAL ISOLATE

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bidi_isolate', self::isolate(...)),
        ];
    }

    public static function isolate(?string $value): string
    {
        if ($value === null || $value === '') {
            return (string) $value;
        }

        return self::ISOLATE_START.$value.self::ISOLATE_END;
    }
}
