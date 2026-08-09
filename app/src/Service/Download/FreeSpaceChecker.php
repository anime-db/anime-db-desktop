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

use App\Service\Exception\InsufficientDiskSpaceException;
use App\Service\Exception\InvalidTorrentFileException;

/**
 * A cheap, best-effort precheck that a torrent's total size fits the free space on the
 * configured downloads root's volume (issue #348) — a UX smoothing, NOT a guarantee: qBittorrent
 * itself still has to handle a real ENOSPC gracefully (a race between this check and the actual
 * write, another process filling the disk, etc. are all still possible).
 *
 * A `.torrent` file's size is known up front (see parseTotalSize()), so
 * {@see QbittorrentDownloadService} calls
 * {@see self::assertEnoughSpaceForTorrentFile()} synchronously before ever adding it to
 * qBittorrent. A magnet's size is unknown until qBittorrent has fetched its metadata, so
 * {@see DownloadCompletionPoller} instead calls
 * {@see self::hasEnoughFreeSpace()} directly, once the polled torrent's reported size becomes
 * non-zero.
 */
final class FreeSpaceChecker
{
    /**
     * Reserve on top of a torrent's total size: max(totalSize * OVERHEAD_RATIO,
     * MIN_OVERHEAD_BYTES). Deliberately a hard-coded constant, not a user-facing setting — a
     * knob would be excessive for what is only a UX smoothing (see class docblock).
     */
    private const float OVERHEAD_RATIO = 0.02;
    private const int MIN_OVERHEAD_BYTES = 256 * 1024 * 1024;

    /**
     * Same purpose as TorrentInfoHashResolver::MAX_NESTING_DEPTH: a `.torrent` file is untrusted
     * input, so decode() below caps how deep it will recurse into nested bencode lists/dicts
     * rather than risk exhausting the PHP call stack on an adversarial payload.
     */
    private const int MAX_NESTING_DEPTH = 100;

    public function __construct(
        private readonly DownloadFolderJail $jail,
        private readonly FreeSpaceProvider $freeSpaceProvider,
    ) {
    }

    /**
     * @throws InsufficientDiskSpaceException if the torrent's total size (plus overhead) does
     *                                        not fit the downloads root's free space
     * @throws InvalidTorrentFileException    if $torrentFileContent is not a well-formed
     *                                        bencoded dictionary with a usable "info" dict
     */
    public function assertEnoughSpaceForTorrentFile(string $torrentFileContent): void
    {
        $totalSize = $this->parseTotalSize($torrentFileContent);

        if (!$this->hasEnoughFreeSpace($totalSize)) {
            throw new InsufficientDiskSpaceException(\sprintf('Torrent needs %d bytes (+ overhead) but the downloads root does not have enough free space.', $totalSize));
        }
    }

    public function hasEnoughFreeSpace(int $totalSize): bool
    {
        $free = $this->freeSpaceProvider->getFreeBytes($this->jail->getRoot());

        // Unknown free space (e.g. the downloads root does not exist yet, a brand-new install
        // before qBittorrent has created it) must not block a legitimate download — this check
        // is a smoothing, not a guarantee (see class docblock); qBittorrent's own ENOSPC
        // handling remains the real backstop either way.
        if ($free === null) {
            return true;
        }

        return $free >= $totalSize + $this->overheadFor($totalSize);
    }

    private function overheadFor(int $totalSize): int
    {
        return (int) max($totalSize * self::OVERHEAD_RATIO, self::MIN_OVERHEAD_BYTES);
    }

    private function parseTotalSize(string $content): int
    {
        $pos = 0;
        $decoded = $this->decode($content, $pos, 0);

        if (!\is_array($decoded) || !isset($decoded['info']) || !\is_array($decoded['info'])) {
            throw new InvalidTorrentFileException('Torrent file has no top-level "info" dictionary.');
        }

        $info = $decoded['info'];

        // Single-file torrent: "info" carries "length" directly.
        if (isset($info['length'])) {
            return (int) $info['length'];
        }

        // Multi-file torrent: "info.files" is a list of {length, path} dicts, summed.
        if (isset($info['files']) && \is_array($info['files'])) {
            $total = 0;
            foreach ($info['files'] as $file) {
                $total += \is_array($file) ? (int) ($file['length'] ?? 0) : 0;
            }

            return $total;
        }

        throw new InvalidTorrentFileException('Torrent file "info" dictionary has neither "length" nor "files".');
    }

    /**
     * A minimal, generic bencode decoder — deliberately separate from
     * TorrentInfoHashResolver::skipValue(), which never decodes a value (it only walks past it
     * to find the "info" dict's byte span for hashing). This needs actual decoded
     * integers/strings/dicts to sum a torrent's total size.
     *
     * @return array<array-key, mixed>|int|string
     */
    private function decode(string $data, int &$pos, int $depth): array|int|string
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new InvalidTorrentFileException(\sprintf('Bencoded list/dictionary nesting exceeds %d levels.', self::MAX_NESTING_DEPTH));
        }

        if ($pos >= \strlen($data)) {
            throw new InvalidTorrentFileException('Unexpected end of torrent file.');
        }

        return match (true) {
            $data[$pos] === 'i' => $this->decodeInteger($data, $pos),
            $data[$pos] === 'l' => $this->decodeList($data, $pos, $depth),
            $data[$pos] === 'd' => $this->decodeDict($data, $pos, $depth),
            ctype_digit($data[$pos]) => $this->decodeString($data, $pos),
            default => throw new InvalidTorrentFileException(\sprintf('Unexpected bencode token "%s" at offset %d.', $data[$pos], $pos)),
        };
    }

    private function decodeInteger(string $data, int &$pos): int
    {
        $end = strpos($data, 'e', $pos);
        if ($end === false) {
            throw new InvalidTorrentFileException('Unterminated bencoded integer.');
        }

        $value = substr($data, $pos + 1, $end - $pos - 1);
        $pos = $end + 1;

        if ($value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidTorrentFileException('Invalid bencoded integer.');
        }

        return (int) $value;
    }

    /** @return list<mixed> */
    private function decodeList(string $data, int &$pos, int $depth): array
    {
        ++$pos;
        $result = [];
        $length = \strlen($data);
        while ($pos < $length && $data[$pos] !== 'e') {
            $result[] = $this->decode($data, $pos, $depth + 1);
        }

        if ($pos >= $length) {
            throw new InvalidTorrentFileException('Unterminated bencoded list.');
        }
        ++$pos;

        return $result;
    }

    /** @return array<string, mixed> */
    private function decodeDict(string $data, int &$pos, int $depth): array
    {
        ++$pos;
        $result = [];
        $length = \strlen($data);
        while ($pos < $length && $data[$pos] !== 'e') {
            $key = $this->decodeString($data, $pos);
            $result[$key] = $this->decode($data, $pos, $depth + 1);
        }

        if ($pos >= $length) {
            throw new InvalidTorrentFileException('Unterminated bencoded dictionary.');
        }
        ++$pos;

        return $result;
    }

    private function decodeString(string $data, int &$pos): string
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

        $pos = $start + $stringLength;

        return substr($data, $start, $stringLength);
    }
}
