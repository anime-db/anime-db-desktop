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

use Doctrine\ORM\Mapping as ORM;

/**
 * External reference link (e.g. Shikimori/MAL page) the catalog entry was sourced from.
 */
#[ORM\Entity]
#[ORM\Index(name: 'IDX_ANIME_SOURCE_ANIME', fields: ['anime'])]
class AnimeSource
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anime::class, inversedBy: 'sources')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Column(length: 512)]
    public readonly string $url;

    public const MAX_URL_LENGTH = 512;

    public function __construct(Anime $anime, string $url)
    {
        $this->anime = $anime;
        $this->url = $url;
    }

    /**
     * Brings a url with raw non-ASCII characters to its ASCII form: bytes outside ASCII in the path,
     * query and fragment are percent-encoded, an IDN host goes through punycode, the scheme is lower-cased.
     * Already encoded urls are not encoded twice,
     * spaces and control characters are left alone so such garbage is still rejected by {@see isValidUrl()}.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = preg_replace_callback('~^(https?)(://)~i', static fn (array $m): string => strtolower($m[1]).$m[2], $url) ?? $url;
        if (preg_match('/[\x80-\xFF]/', $url) !== 1) {
            return $url;
        }

        if (preg_match('~^(https?://)([^/?#]*)(.*)$~is', $url, $m) === 1) {
            [, $scheme, $authority, $rest] = $m;
            if (preg_match('/^(.*@)?([^:@\[\]]*)(:\d*)?$/s', $authority, $a) === 1 && preg_match('/[\x80-\xFF]/', $a[2]) === 1) {
                $host = idn_to_ascii($a[2], \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
                if ($host === false) {
                    return $url;
                }
                $authority = $a[1].$host.($a[3] ?? '');
            }
            $url = $scheme.$authority.$rest;
        }

        return preg_replace_callback('/[\x80-\xFF]/', static fn (array $m): string => rawurlencode($m[0]), $url) ?? $url;
    }

    /** The single rule for an acceptable source url: absolute http(s), valid characters, fits the column. */
    public static function isValidUrl(string $url): bool
    {
        return mb_strlen($url) <= self::MAX_URL_LENGTH
            && filter_var($url, \FILTER_VALIDATE_URL) !== false
            && \in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /**
     * Comparison key: scheme and `www.` ignored, host lower-cased, no trailing slash. Falls back
     * to the raw url when it cannot be parsed, so unusual legacy values still compare by identity.
     */
    public static function key(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $key = preg_replace('/^www\./', '', strtolower($parts['host']))
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $key .= '?'.$parts['query'];
        }

        return rtrim($key, '/');
    }
}
