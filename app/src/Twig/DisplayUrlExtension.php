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
use Twig\TwigFilter;

/**
 * Presentation-only readable form of a stored (ASCII) source address (issue #1045).
 *
 * The host is shown in Unicode unless it looks like a homograph spoof (then the punycode stays),
 * and only `%XX` runs forming a valid, safe non-ASCII UTF-8 character are decoded in the rest of
 * the address. Nothing here changes what is stored, compared or sent over the network.
 */
final class DisplayUrlExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('display_url', self::displayUrl(...)),
            new TwigFilter('display_host', self::displayHost(...)),
        ];
    }

    public static function displayHost(string $host): string
    {
        if ($host === '' || !str_contains(strtolower($host), 'xn--')) {
            return $host;
        }

        $unicode = idn_to_utf8($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
        if ($unicode === false || $unicode === '' || $unicode === $host) {
            return $host;
        }

        // the readable host must normalize back to the stored one (transitional processing maps
        // e.g. "ß" to "ss", which is a different domain)
        $back = idn_to_ascii($unicode, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
        if ($back === false || strtolower($back) !== strtolower($host)) {
            return $host;
        }

        $checker = new \Spoofchecker();
        $checker->setChecks(
            \Spoofchecker::MIXED_SCRIPT_CONFUSABLE
            | \Spoofchecker::WHOLE_SCRIPT_CONFUSABLE
            | \Spoofchecker::INVISIBLE
            | \Spoofchecker::CHAR_LIMIT
        );
        // mixing scripts (e.g. Cyrillic + Latin) is only caught by the restriction level check
        $checker->setRestrictionLevel(\Spoofchecker::HIGHLY_RESTRICTIVE);

        // ASCII subdomains ("www.") must not taint an IDN label, but the TLD stays in the check so
        // that a Cyrillic name under a Latin TLD is still treated as mixed-script
        $labels = explode('.', $unicode);
        $last = array_key_last($labels);
        $labels = array_filter(
            $labels,
            static fn (string $label, int $i): bool => $i === $last || preg_match('/[\x80-\xFF]/', $label) === 1,
            \ARRAY_FILTER_USE_BOTH,
        );
        if ($checker->isSuspicious(implode('.', $labels))) {
            return $host;
        }

        return $unicode;
    }

    public static function displayUrl(string $url): string
    {
        if (preg_match('~^([a-z][a-z0-9+.-]*://)([^/?#]*)(.*)$~is', $url, $m) !== 1) {
            return $url;
        }

        [, $scheme, $authority, $rest] = $m;
        $at = strrpos($authority, '@');
        $userinfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        $port = '';
        if ($hostPort !== '' && $hostPort[0] !== '[' && preg_match('/^(.*?)(:\d*)$/', $hostPort, $pm) === 1) {
            [, $hostPort, $port] = $pm;
        }

        return $scheme.$userinfo.self::displayHost($hostPort).$port.self::decodeEscapes($rest);
    }

    private static function decodeEscapes(string $value): string
    {
        return (string) preg_replace_callback(
            // upper-case only: normalization re-encodes with upper-case hex, so lower-case escapes would not round-trip
            '/(?:%[89A-F][0-9A-F])+/',
            static function (array $match): string {
                $escapes = str_split($match[0], 3);
                $bytes = '';
                foreach ($escapes as $escape) {
                    $bytes .= hex2bin(substr($escape, 1));
                }

                $out = '';
                $length = strlen($bytes);
                for ($i = 0; $i < $length;) {
                    $char = null;
                    if (preg_match('/\G(?:[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3})/', $bytes, $cm, 0, $i) === 1
                        && mb_check_encoding($cm[0], 'UTF-8')
                        && self::isSafe($cm[0])
                    ) {
                        $char = $cm[0];
                    }

                    $out .= $char ?? $escapes[$i];
                    $i += $char === null ? 1 : strlen($char);
                }

                return $out;
            },
            $value,
        );
    }

    private static function isSafe(string $char): bool
    {
        if (preg_match('/^[\p{C}\p{Z}]$/u', $char) === 1) {
            return false;
        }

        $codePoint = \IntlChar::ord($char);

        return !\IntlChar::hasBinaryProperty($codePoint, \IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT);
    }
}
