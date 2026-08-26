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

namespace App\Service\I18nCoverage;

/**
 * Resolves the GitHub handle(s) responsible for `plugins/<id>/` from the plugins monorepo's own
 * `.github/CODEOWNERS` content — the same file GitHub itself reads for review assignment, kept as
 * the single source of truth rather than a hand-maintained id => handle table (that table would
 * drift the moment CODEOWNERS is updated without this script also being updated).
 *
 * A pure string-in, string-out parser: no file I/O of its own, so it needs no fake to test — a
 * literal CODEOWNERS fixture string is enough.
 *
 * Matching is intentionally narrow: a pattern matches only when it is (ignoring a leading/
 * trailing `/`) exactly `plugins/<id>`, the one shape every entry in the real CODEOWNERS file
 * uses (`/plugins/<id>/ @<handle>`). Glob patterns are not interpreted. Lines are still read top
 * to bottom with the *last* matching one winning (GitHub's own CODEOWNERS precedence rule), so a
 * duplicate entry for the same plugin resolves the same way GitHub itself would resolve it. Only
 * `@handle` owners are recognised (CODEOWNERS also allows bare e-mail addresses, which cannot be
 * `@mentioned` in an issue body and are therefore not useful here).
 */
final class CodeownersOwnerResolver
{
    /**
     * @return list<string> `@handle` mentions for $pluginId, in the order CODEOWNERS lists them
     *                      for the winning pattern — empty when nothing matches
     */
    public static function resolve(string $codeownersContent, string $pluginId): array
    {
        $target = 'plugins/'.$pluginId;
        $owners = [];

        foreach (preg_split('/\R/', $codeownersContent) ?: [] as $line) {
            [$pattern, $lineOwners] = self::parseLine($line);
            if ($pattern !== null && trim($pattern, '/') === $target) {
                $owners = $lineOwners;
            }
        }

        return $owners;
    }

    /**
     * @return array{0: string|null, 1: list<string>}
     */
    private static function parseLine(string $line): array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return [null, []];
        }

        $tokens = preg_split('/\s+/', $line) ?: [];
        if ($tokens === []) {
            return [null, []];
        }

        $pattern = array_shift($tokens);
        $owners = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => str_starts_with($token, '@'),
        ));

        return [$pattern, $owners];
    }
}
