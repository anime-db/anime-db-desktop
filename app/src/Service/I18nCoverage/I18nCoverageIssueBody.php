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
 * The delta is embedded as an HTML comment carrying JSON right after the human-readable part:
 * invisible when the issue is rendered, trivial to parse back out, and immune to a human lightly
 * editing the prose above it. With no placeholder-divergent keys the JSON is a sorted array of the
 * missing keys (the original format, still what older open issues carry); otherwise it is an object
 * `{"missing": [...], "divergent": [...]}`.
 */
final class I18nCoverageIssueBody
{
    private const string DELTA_MARKER_PREFIX = '<!-- i18n-coverage:delta:';
    private const string DELTA_MARKER_SUFFIX = ' -->';

    /**
     * @param list<string>                                                                    $missingKeys   sorted
     * @param list<string>                                                                    $owners        `@handle` mentions, empty when CODEOWNERS has no entry
     * @param list<string>                                                                    $orphanedKeys  sorted; keys the plugin still ships that the app no longer
     *                                                                                                       references (renamed or removed on the app side). Purely
     *                                                                                                       informational — not part of the delta tracked by
     *                                                                                                       {@see I18nCoverageIssueDecider} or the marker parsed back
     *                                                                                                       out by {@see parsePreviousDelta()}
     * @param array<string, array<string, array{missing: list<string>, extra: list<string>}>> $divergentKeys
     *                                                                                                       key => plugin locale => placeholder diff against the app, sorted
     */
    public static function render(
        string $pluginId,
        array $missingKeys,
        array $owners,
        array $orphanedKeys = [],
        array $divergentKeys = [],
    ): string {
        $lines = [
            \sprintf('Перевод плагина `%s` отстаёт от приложения.', $pluginId),
            \sprintf('Отсутствует ключей перевода: %d.', \count($missingKeys)),
        ];

        if ($divergentKeys !== []) {
            $lines[] = \sprintf('Ключей с расходящимися плейсхолдерами: %d.', \count($divergentKeys));
        }

        $lines[] = '';
        $lines[] = 'Issue закроется автоматически, когда очередной прогон снова не найдёт отсутствующих ключей '
            .'и ключей с расходящимися плейсхолдерами '
            .'(то есть не раньше следующего релиза приложения — версия в это сравнение не входит).';

        if ($missingKeys !== []) {
            $lines[] = '';
            $lines[] = 'Отсутствующие ключи:';
            $lines[] = '';
            foreach ($missingKeys as $key) {
                $lines[] = \sprintf('- `%s`', $key);
            }
        }

        if ($divergentKeys !== []) {
            $lines[] = '';
            $lines[] = 'Расходятся плейсхолдеры с приложением:';
            $lines[] = '';
            foreach ($divergentKeys as $key => $locales) {
                foreach ($locales as $locale => $diff) {
                    $lines[] = \sprintf(
                        '- `%s` (%s): не хватает %s; лишние %s',
                        $key,
                        $locale,
                        self::formatPlaceholders($diff['missing']),
                        self::formatPlaceholders($diff['extra']),
                    );
                }
            }
        }

        if ($orphanedKeys !== []) {
            $lines[] = '';
            $lines[] = 'Ключей больше нет в приложении:';
            $lines[] = '';
            $lines[] = 'Их можно удалить из каталога плагина. Если ключ был переименован в приложении, '
                .'перенесите уже готовый перевод на новое имя вместо того, чтобы переводить его заново.';
            $lines[] = '';
            foreach ($orphanedKeys as $key) {
                $lines[] = \sprintf('- `%s`', $key);
            }
        }

        if ($owners !== []) {
            $lines[] = '';
            $lines[] = \sprintf('cc %s', implode(' ', $owners));
        }

        $divergentNames = array_keys($divergentKeys);
        $delta = $divergentNames === [] ? $missingKeys : ['missing' => $missingKeys, 'divergent' => $divergentNames];

        $lines[] = '';
        $lines[] = self::DELTA_MARKER_PREFIX.json_encode($delta, \JSON_THROW_ON_ERROR).self::DELTA_MARKER_SUFFIX;

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $names
     */
    private static function formatPlaceholders(array $names): string
    {
        if ($names === []) {
            return '—';
        }

        return implode(', ', array_map(static fn (string $name): string => \sprintf('`%%%s%%`', $name), $names));
    }

    /**
     * @param list<string> $previousMissingKeys   sorted
     * @param list<string> $missingKeys           sorted
     * @param list<string> $previousDivergentKeys sorted
     * @param list<string> $divergentKeys         sorted
     */
    public static function renderComment(
        array $previousMissingKeys,
        array $missingKeys,
        array $previousDivergentKeys = [],
        array $divergentKeys = [],
    ): string {
        $sections = [
            'Стали отсутствовать:' => array_diff($missingKeys, $previousMissingKeys),
            'Больше не отсутствуют:' => array_diff($previousMissingKeys, $missingKeys),
            'Стали расходиться по плейсхолдерам:' => array_diff($divergentKeys, $previousDivergentKeys),
            'Больше не расходятся по плейсхолдерам:' => array_diff($previousDivergentKeys, $divergentKeys),
        ];

        $lines = ['Дельта изменилась с прошлого прогона.'];

        foreach ($sections as $title => $keys) {
            if ($keys === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = $title;
            foreach ($keys as $key) {
                $lines[] = \sprintf('- `%s`', $key);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> sorted missing keys; empty when $body carries no marker or the marker
     *                      does not parse. An old-format marker (plain JSON array) is read as
     *                      "missing keys only"
     */
    public static function parsePreviousDelta(string $body): array
    {
        return self::parseMarker($body)['missing'];
    }

    /**
     * @return list<string> sorted placeholder-divergent keys; empty for an old-format marker
     */
    public static function parsePreviousDivergentDelta(string $body): array
    {
        return self::parseMarker($body)['divergent'];
    }

    /**
     * @return array{missing: list<string>, divergent: list<string>}
     */
    private static function parseMarker(string $body): array
    {
        $empty = ['missing' => [], 'divergent' => []];

        $start = strpos($body, self::DELTA_MARKER_PREFIX);
        if ($start === false) {
            return $empty;
        }

        $jsonStart = $start + \strlen(self::DELTA_MARKER_PREFIX);
        $end = strpos($body, self::DELTA_MARKER_SUFFIX, $jsonStart);
        if ($end === false) {
            return $empty;
        }

        try {
            $decoded = json_decode(substr($body, $jsonStart, $end - $jsonStart), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $empty;
        }

        if (!\is_array($decoded)) {
            return $empty;
        }

        if (array_is_list($decoded)) {
            return ['missing' => self::stringKeys($decoded), 'divergent' => []];
        }

        return [
            'missing' => self::stringKeys(\is_array($decoded['missing'] ?? null) ? $decoded['missing'] : []),
            'divergent' => self::stringKeys(\is_array($decoded['divergent'] ?? null) ? $decoded['divergent'] : []),
        ];
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function stringKeys(array $values): array
    {
        $keys = array_values(array_filter($values, static fn (mixed $key): bool => \is_string($key)));
        sort($keys);

        return $keys;
    }
}
