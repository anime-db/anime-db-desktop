<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Download;

use App\Service\Exception\InvalidTorrentFileException;

/**
 * Computes the BitTorrent v1 infohash a {@see \AnimeDb\PluginContracts\Download\DownloadSource}
 * resolves to, BEFORE it is ever handed to qBittorrent: QbittorrentDownloadService needs the
 * infohash up front to check for an existing {@see \App\Entity\Download} row (idempotent
 * enqueue) and to build the jailed save-path a NEW torrent is added under.
 *
 * A magnet URI already carries its infohash in the "xt=urn:btih:" parameter (hex or base32, per
 * BEP 9) — trivial to extract. A `.torrent` file does not: its infohash is the SHA-1 of the exact
 * bytes of its "info" dictionary, so the file has to be parsed. Rather than decode the whole
 * bencode structure and re-encode "info" (relying on bencode's canonical form to round-trip
 * byte-for-byte), this walks just far enough to find the "info" value's byte span and hashes that
 * slice directly — no re-encoding, so there is no risk of a subtly non-canonical re-encoding
 * producing the wrong hash.
 *
 * Every hash this class returns is normalized to the lowercase 40-char hex form qBittorrent's
 * WebUI reports torrents under (see {@see \App\Entity\Download::INFO_HASH_PATTERN}), so a magnet
 * and a .torrent file for the same torrent, or a hash echoed back by qBittorrent, all compare equal.
 */
final class TorrentInfoHashResolver
{
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * skipValue() recurses once per nested bencode list/dict level. A real torrent's "info" dict
     * never nests more than a handful of levels deep (info -> files -> file-dict -> path-list ->
     * strings); this caps it far above that so a malicious `.torrent` (untrusted input, per this
     * class's docblock) built as thousands of nested "l"/"d" tokens cannot exhaust the PHP call
     * stack — an uncatchable fatal, not something `catch (InvalidTorrentFileException)` stops.
     */
    private const int MAX_NESTING_DEPTH = 100;

    public function fromMagnet(string $magnetUri): string
    {
        if (preg_match('/xt=urn:btih:([a-zA-Z0-9]{32,40})/', $magnetUri, $matches) !== 1) {
            throw new InvalidTorrentFileException(\sprintf('"%s" has no "xt=urn:btih:" infohash.', $magnetUri));
        }

        return $this->normalize($matches[1]);
    }

    public function fromTorrentFileContent(string $content): string
    {
        return bin2hex(sha1($this->extractInfoDictBytes($content), true));
    }

    private function normalize(string $hash): string
    {
        if (\strlen($hash) === 40) {
            return strtolower($hash);
        }

        return bin2hex($this->base32Decode(strtoupper($hash)));
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                throw new InvalidTorrentFileException(\sprintf('"%s" is not a valid base32 infohash.', $encoded));
            }

            $bits .= str_pad(decbin($value), 5, '0', \STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split(substr($bits, 0, \intdiv(\strlen($bits), 8) * 8), 8) as $byte) {
            $bytes .= \chr(bindec($byte));
        }

        return $bytes;
    }

    private function extractInfoDictBytes(string $data): string
    {
        $length = \strlen($data);
        if ($length === 0 || $data[0] !== 'd') {
            throw new InvalidTorrentFileException('Not a valid bencoded torrent file (expected a top-level dictionary).');
        }

        $pos = 1;
        while ($pos < $length && $data[$pos] !== 'e') {
            [$key, $pos] = $this->readString($data, $pos);
            $valueStart = $pos;
            $pos = $this->skipValue($data, $pos, 0);

            if ($key === 'info') {
                return substr($data, $valueStart, $pos - $valueStart);
            }
        }

        throw new InvalidTorrentFileException('Torrent file has no top-level "info" dictionary.');
    }

    private function skipValue(string $data, int $pos, int $depth): int
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new InvalidTorrentFileException(\sprintf('Bencoded list/dictionary nesting exceeds %d levels.', self::MAX_NESTING_DEPTH));
        }

        $length = \strlen($data);
        if ($pos >= $length) {
            throw new InvalidTorrentFileException('Unexpected end of torrent file.');
        }

        $type = $data[$pos];

        if ($type === 'i') {
            $end = strpos($data, 'e', $pos);
            if ($end === false) {
                throw new InvalidTorrentFileException('Unterminated bencoded integer.');
            }

            return $end + 1;
        }

        if ($type === 'l' || $type === 'd') {
            ++$pos;
            while ($pos < $length && $data[$pos] !== 'e') {
                if ($type === 'd') {
                    [, $pos] = $this->readString($data, $pos);
                }
                $pos = $this->skipValue($data, $pos, $depth + 1);
            }

            if ($pos >= $length) {
                throw new InvalidTorrentFileException('Unterminated bencoded list/dictionary.');
            }

            return $pos + 1;
        }

        if (ctype_digit($type)) {
            [, $pos] = $this->readString($data, $pos);

            return $pos;
        }

        throw new InvalidTorrentFileException(\sprintf('Unexpected bencode token "%s" at offset %d.', $type, $pos));
    }

    /** @return array{0: string, 1: int} */
    private function readString(string $data, int $pos): array
    {
        $colon = strpos($data, ':', $pos);
        if ($colon === false) {
            throw new InvalidTorrentFileException('Unterminated bencoded string length.');
        }

        $lengthPart = substr($data, $pos, $colon - $pos);
        if ($lengthPart === '' || !ctype_digit($lengthPart)) {
            throw new InvalidTorrentFileException('Invalid bencoded string length.');
        }

        $stringLength = (int) $lengthPart;
        $start = $colon + 1;

        if ($start + $stringLength > \strlen($data)) {
            throw new InvalidTorrentFileException('Bencoded string length exceeds file size.');
        }

        return [substr($data, $start, $stringLength), $start + $stringLength];
    }
}
