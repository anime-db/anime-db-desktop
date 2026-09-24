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

namespace App\Service\Media;

use AnimeDb\PluginContracts\Media\AudioTrack;
use AnimeDb\PluginContracts\Media\MediaInfo;
use AnimeDb\PluginContracts\Media\OtherTrack;
use AnimeDb\PluginContracts\Media\SubtitleTrack;
use AnimeDb\PluginContracts\Media\VideoTrack;
use App\Service\Exception\FfprobeOutputException;

/**
 * Turns the JSON printed by `-print_format json -show_format -show_streams` into a
 * {@see MediaInfo}. Pure text-in, value-out: it knows nothing about how the text was produced.
 *
 * Every stream is placed in exactly one of the four track lists — `video`, `audio` and `subtitle`
 * by `codec_type`, anything else (attachment, data, a type not seen before) into `OtherTrack` —
 * and `trackCount` is the number of streams reported, so the {@see MediaInfo} invariant holds by
 * construction.
 *
 * The prober's "no data" sentinels (`"0/0"`, `"N/A"`, `"unknown"`, `-99`, empty string) become
 * `null`, never zero. `sizeBytes` comes from `format.size`, i.e. the file as it was when read.
 * A missing codec name on a typed track is reported as an empty string, since the contract
 * requires a string there.
 *
 * Required input: `format.format_name`, `format.size`, `streams`, and `index` and `codec_type` in
 * every stream. Anything else absent gives `null`.
 */
final class FfprobeOutputMapper
{
    /**
     * @throws FfprobeOutputException
     */
    public function map(string $json, string $probeIdentity): MediaInfo
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FfprobeOutputException('The prober output is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($data)) {
            throw new FfprobeOutputException('The prober output is not a JSON object.');
        }

        $format = $data['format'] ?? null;
        if (!\is_array($format)) {
            throw new FfprobeOutputException('The prober output has no "format" section.');
        }

        $containerFormat = $this->normalize($format['format_name'] ?? null);
        if (!\is_string($containerFormat)) {
            throw new FfprobeOutputException('The prober output has no "format.format_name".');
        }

        $sizeBytes = $this->toInt($format['size'] ?? null);
        if ($sizeBytes === null) {
            throw new FfprobeOutputException('The prober output has no valid "format.size".');
        }

        $streams = $data['streams'] ?? null;
        if (!\is_array($streams)) {
            throw new FfprobeOutputException('The prober output has no "streams" section.');
        }

        $video = $audio = $subtitles = $other = [];
        foreach ($streams as $stream) {
            if (!\is_array($stream)) {
                throw new FfprobeOutputException('A stream entry is not a JSON object.');
            }

            $index = $this->toInt($stream['index'] ?? null);
            if ($index === null) {
                throw new FfprobeOutputException('A stream has no valid "index".');
            }

            $type = $stream['codec_type'] ?? null;
            if (!\is_string($type) || $type === '') {
                throw new FfprobeOutputException(\sprintf('Stream %d has no "codec_type".', $index));
            }

            $codec = $this->normalize($stream['codec_name'] ?? null);
            $codec = \is_string($codec) ? $codec : null;
            $tags = array_change_key_case(\is_array($stream['tags'] ?? null) ? $stream['tags'] : [], \CASE_LOWER);
            $disposition = \is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];

            switch ($type) {
                case 'video':
                    $video[] = new VideoTrack(
                        $index,
                        $codec ?? '',
                        $this->toString($stream['profile'] ?? null),
                        $this->toInt($stream['width'] ?? null),
                        $this->toInt($stream['height'] ?? null),
                        $this->toString($stream['pix_fmt'] ?? null),
                        $this->toFloat($stream['r_frame_rate'] ?? null),
                        $this->toInt($stream['bit_rate'] ?? null),
                    );
                    break;
                case 'audio':
                    $audio[] = new AudioTrack(
                        $index,
                        $codec ?? '',
                        $this->toInt($stream['channels'] ?? null),
                        $this->toString($stream['channel_layout'] ?? null),
                        $this->toInt($stream['sample_rate'] ?? null),
                        $this->toInt($stream['bit_rate'] ?? null),
                        $this->toString($tags['language'] ?? null),
                        $this->toString($tags['title'] ?? null),
                        $this->toBool($disposition['default'] ?? null),
                    );
                    break;
                case 'subtitle':
                    $subtitles[] = new SubtitleTrack(
                        $index,
                        $codec ?? '',
                        $this->toString($tags['language'] ?? null),
                        $this->toString($tags['title'] ?? null),
                        $this->toBool($disposition['default'] ?? null),
                        $this->toBool($disposition['forced'] ?? null),
                    );
                    break;
                default:
                    $other[] = new OtherTrack($index, $type, $codec);
            }
        }

        return new MediaInfo(
            $containerFormat,
            $this->toFloat($format['duration'] ?? null),
            $sizeBytes,
            $this->toInt($format['bit_rate'] ?? null),
            \count($streams),
            $video,
            $audio,
            $subtitles,
            $other,
            $probeIdentity,
        );
    }

    /**
     * Returns `null` for the prober's "no data" sentinels and for anything that is not a scalar.
     */
    private function normalize(mixed $value): int|float|string|bool|null
    {
        if (!\is_scalar($value)) {
            return null;
        }
        if (\is_string($value)) {
            $value = trim($value);
            if ($value === '' || $value === '0/0' || $value === '-99' || strcasecmp($value, 'N/A') === 0 || strcasecmp($value, 'unknown') === 0) {
                return null;
            }

            return $value;
        }

        return $value === -99 ? null : $value;
    }

    private function toString(mixed $value): ?string
    {
        $value = $this->normalize($value);

        return \is_string($value) ? $value : null;
    }

    private function toInt(mixed $value): ?int
    {
        $value = $this->normalize($value);
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Accepts numbers, numeric strings and `a/b` fractions such as `24000/1001`.
     */
    private function toFloat(mixed $value): ?float
    {
        $value = $this->normalize($value);
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (!\is_string($value)) {
            return null;
        }
        if (\is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)$/', $value, $m) === 1 && (float) $m[2] !== 0.0) {
            return (float) $m[1] / (float) $m[2];
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
