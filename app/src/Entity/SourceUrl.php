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
 * Common comparison steps of a source url (the value of `anime_source.normalized_url` before site
 * rules). Static and dependency-free so that the domain entities can compare links without a service.
 */
final class SourceUrl
{
    public const MAX_LENGTH = 1024;

    private const DEFAULT_PORTS = [80, 443];

    /**
     * Common steps: https scheme, lower-case punycode host without `www.` and default port, no
     * fragment, canonical percent-encoding, query parameters stable-sorted by name, no trailing
     * slash except for the root. An url that cannot be parsed is returned unchanged, so unusual
     * legacy values still compare by identity.
     */
    public static function common(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return $url;
        }

        $host = strtolower($parts['host']);
        if (preg_match('/[\x80-\xFF]/', $host) === 1) {
            $ascii = idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $result = 'https://';
        if (isset($parts['user'])) {
            $result .= $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }
        $result .= $host;
        if (isset($parts['port']) && !\in_array($parts['port'], self::DEFAULT_PORTS, true)) {
            $result .= ':'.$parts['port'];
        }

        $path = self::encode($parts['path'] ?? '');
        $path = rtrim($path, '/');
        $result .= $path === '' ? '/' : $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $result .= '?'.self::sortQuery(self::encode($parts['query']));
        }

        return $result;
    }

    /** Stable sort by parameter name, bytewise: equal names keep their original order. */
    private static function sortQuery(string $query): string
    {
        $params = [];
        foreach (explode('&', $query) as $index => $param) {
            $params[] = [explode('=', $param, 2)[0], $index, $param];
        }
        usort($params, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: $a[1] <=> $b[1]);

        return implode('&', array_column($params, 2));
    }

    /** Decodes unreserved characters, upper-cases the hex of the rest and encodes everything that is not allowed raw. */
    private static function encode(string $value): string
    {
        $result = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})|%|[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?]/',
            static function (array $m): string {
                if (isset($m[1])) {
                    $char = \chr((int) hexdec($m[1]) & 0xFF);

                    return preg_match('/^[A-Za-z0-9\-._~]$/', $char) === 1 ? $char : '%'.strtoupper($m[1]);
                }

                return '%'.strtoupper(bin2hex($m[0]));
            },
            $value,
        );

        return $result ?? $value;
    }
}
