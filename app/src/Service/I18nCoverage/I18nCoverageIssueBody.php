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
 * Renders an `i18n-coverage` issue body and reads the previous run's delta back out of it — the
 * single place both directions of that round trip are implemented, so they cannot drift apart
 * (the body is the only persisted state between runs; there is no separate store).
 *
 * The delta is embedded as an HTML comment carrying a sorted JSON array right after the
 * human-readable part: invisible when the issue is rendered, trivial to parse back out, and
 * immune to a human lightly editing the prose above it.
 */
final class I18nCoverageIssueBody
{
    private const string DELTA_MARKER_PREFIX = '<!-- i18n-coverage:delta:';
    private const string DELTA_MARKER_SUFFIX = ' -->';

    /**
     * @param list<string> $missingKeys sorted
     * @param list<string> $owners      `@handle` mentions, empty when CODEOWNERS has no entry
     */
    public static function render(string $pluginId, array $missingKeys, array $owners): string
    {
        $lines = [
            \sprintf('Перевод плагина `%s` отстаёт от приложения.', $pluginId),
            \sprintf('Отсутствует ключей перевода: %d.', \count($missingKeys)),
            '',
            'Issue закроется автоматически, когда очередной прогон снова не найдёт отсутствующих ключей '
            .'(то есть не раньше следующего релиза приложения — версия в это сравнение не входит).',
            '',
            'Отсутствующие ключи:',
            '',
        ];

        foreach ($missingKeys as $key) {
            $lines[] = \sprintf('- `%s`', $key);
        }

        if ($owners !== []) {
            $lines[] = '';
            $lines[] = \sprintf('cc %s', implode(' ', $owners));
        }

        $lines[] = '';
        $lines[] = self::DELTA_MARKER_PREFIX.json_encode($missingKeys, \JSON_THROW_ON_ERROR).self::DELTA_MARKER_SUFFIX;

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $previousMissingKeys sorted
     * @param list<string> $missingKeys         sorted
     */
    public static function renderComment(array $previousMissingKeys, array $missingKeys): string
    {
        $newlyMissing = array_values(array_diff($missingKeys, $previousMissingKeys));
        $newlyCovered = array_values(array_diff($previousMissingKeys, $missingKeys));

        $lines = ['Дельта изменилась с прошлого прогона.'];

        if ($newlyMissing !== []) {
            $lines[] = '';
            $lines[] = 'Стали отсутствовать:';
            foreach ($newlyMissing as $key) {
                $lines[] = \sprintf('- `%s`', $key);
            }
        }

        if ($newlyCovered !== []) {
            $lines[] = '';
            $lines[] = 'Больше не отсутствуют:';
            foreach ($newlyCovered as $key) {
                $lines[] = \sprintf('- `%s`', $key);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> sorted; empty when $body carries no marker or the marker does not
     *                      parse as a JSON array of strings
     */
    public static function parsePreviousDelta(string $body): array
    {
        $start = strpos($body, self::DELTA_MARKER_PREFIX);
        if ($start === false) {
            return [];
        }

        $jsonStart = $start + \strlen(self::DELTA_MARKER_PREFIX);
        $end = strpos($body, self::DELTA_MARKER_SUFFIX, $jsonStart);
        if ($end === false) {
            return [];
        }

        try {
            $decoded = json_decode(substr($body, $jsonStart, $end - $jsonStart), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $keys = array_values(array_filter($decoded, static fn (mixed $key): bool => \is_string($key)));
        sort($keys);

        return $keys;
    }
}
