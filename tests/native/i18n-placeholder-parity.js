/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
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

'use strict';

// Compares Symfony-style %name% placeholders between two translations of the same catalog key.
// The project deliberately uses only this one placeholder syntax (no ICU/pluralization
// catalogs), so a stray '{', '}' or '|' is treated as a syntax violation rather than a
// different-but-valid style.

const FORBIDDEN_CHARACTERS = ['{', '}', '|'];

/**
 * @param {string} value
 * @returns {string[]} sorted, deduplicated %name% placeholder names found in value
 */
function extractPlaceholders(value) {
    const matches = value.match(/%([a-zA-Z0-9_]+)%/g) || [];
    const names   = [...new Set(matches.map((match) => match.slice(1, -1)))];

    return names.sort();
}

/**
 * @param {string} value
 * @returns {string[]} forbidden characters present in value, in the order checked
 */
function findForbiddenCharacters(value) {
    return FORBIDDEN_CHARACTERS.filter((char) => value.includes(char));
}

/**
 * Compares the placeholder sets of two translations of the same key. The order of placeholders
 * inside the string is irrelevant - target-language grammar may require a different order than
 * the source, and that is not a translation defect.
 *
 * @param {string} referenceValue
 * @param {string} otherValue
 * @returns {{missing: string[], extra: string[]} | null} null when the sets match
 */
function diffPlaceholders(referenceValue, otherValue) {
    const reference = extractPlaceholders(referenceValue);
    const other      = extractPlaceholders(otherValue);

    const missing = reference.filter((name) => !other.includes(name));
    const extra   = other.filter((name) => !reference.includes(name));

    if (missing.length === 0 && extra.length === 0) {
        return null;
    }

    return { missing, extra };
}

module.exports = { extractPlaceholders, findForbiddenCharacters, diffPlaceholders };
