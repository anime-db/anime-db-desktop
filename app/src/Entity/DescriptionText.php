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

namespace App\Entity;

/**
 * Canonical form of a description text (issue #1068): every line break becomes "\n", then the
 * edges are trimmed. Stored text, the edit form's change hash and every text comparison use this
 * form, so a textarea's CRLF never looks like an edit and never defeats a comparison.
 */
final class DescriptionText
{
    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\R/u', "\n", $text) ?? $text);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalize($text));
    }
}
