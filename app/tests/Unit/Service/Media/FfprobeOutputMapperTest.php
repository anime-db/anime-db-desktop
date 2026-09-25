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

namespace App\Tests\Unit\Service\Media;

use AnimeDb\PluginContracts\Media\AudioTrack;
use AnimeDb\PluginContracts\Media\MediaInfo;
use AnimeDb\PluginContracts\Media\OtherTrack;
use AnimeDb\PluginContracts\Media\SubtitleTrack;
use AnimeDb\PluginContracts\Media\VideoTrack;
use App\Service\Exception\FfprobeOutputException;
use App\Service\Media\FfprobeOutputMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FfprobeOutputMapperTest extends TestCase
{
    public function testAttachmentStreamsLandInOtherTracks(): void
    {
        $info = $this->mapFixture('attachments.json');

        self::assertCount(1, $info->video);
        self::assertCount(1, $info->audio);
        self::assertSame([], $info->subtitles);
        self::assertEquals([new OtherTrack(2, 'attachment', 'ttf'), new OtherTrack(3, 'attachment', 'ttf')], $info->otherTracks);
        self::assertSame(4, $info->trackCount);
        self::assertSame('matroska,webm', $info->containerFormat);
        self::assertSame(1420.5, $info->durationSeconds);
        self::assertSame(4134000, $info->bitRate);
        self::assertSame('probe-id', $info->probeIdentity);
    }

    public function testUnknownCodecTypeLandsInOtherTracks(): void
    {
        $info = $this->mapFixture('unknown_type.json');

        self::assertEquals([new OtherTrack(1, 'hologram', 'holo'), new OtherTrack(2, 'data', null)], $info->otherTracks);
        self::assertSame(3, $info->trackCount);
        self::assertNull($info->durationSeconds);
        self::assertNull($info->bitRate);
    }

    /**
     * @param array{int, int, int, int} $expected video, audio, subtitles, other
     */
    #[DataProvider('provideTrackCountFixtures')]
    public function testTrackCountEqualsStreamsAndSumOfLists(string $fixture, array $expected): void
    {
        $info = $this->mapFixture($fixture);
        $streams = json_decode((string) file_get_contents($this->path($fixture)), true, 512, \JSON_THROW_ON_ERROR)['streams'];

        self::assertSame([\count($info->video), \count($info->audio), \count($info->subtitles), \count($info->otherTracks)], $expected);
        self::assertSame(\count($streams), $info->trackCount);
        self::assertSame($info->trackCount, array_sum($expected));
    }

    /**
     * @return iterable<string, array{string, array{int, int, int, int}}>
     */
    public static function provideTrackCountFixtures(): iterable
    {
        yield 'attachments' => ['attachments.json', [1, 1, 0, 2]];
        yield 'multitrack' => ['multitrack.json', [1, 2, 2, 0]];
        yield 'no subtitles' => ['no_subtitles.json', [1, 1, 0, 0]];
        yield 'sentinels' => ['sentinels.json', [1, 1, 0, 0]];
        yield 'unknown type' => ['unknown_type.json', [1, 0, 0, 2]];
    }

    public function testMultipleAudioAndSubtitleTracksKeepOrderAndFields(): void
    {
        $info = $this->mapFixture('multitrack.json');

        self::assertEquals([
            new AudioTrack(1, 'flac', 6, '5.1', 44100, 900000, 'jpn', null, true),
            new AudioTrack(2, 'ac3', 2, null, 48000, null, 'eng', 'Commentary', false),
        ], $info->audio);
        self::assertEquals([
            new SubtitleTrack(3, 'ass', 'eng', 'Full', true, false),
            new SubtitleTrack(4, 'subrip', 'rus', null, false, true),
        ], $info->subtitles);
    }

    public function testFileWithoutSubtitlesHasEmptySubtitleList(): void
    {
        self::assertSame([], $this->mapFixture('no_subtitles.json')->subtitles);
    }

    public function testSentinelsBecomeNull(): void
    {
        $info = $this->mapFixture('sentinels.json');

        self::assertEquals(new VideoTrack(0, 'wmv2', null, null, null, null, null, null), $info->video[0]);
        self::assertEquals(new AudioTrack(1, 'wmav2', null, null, null, null, null, null, false), $info->audio[0]);
        self::assertNull($info->durationSeconds);
        self::assertNull($info->bitRate);
        self::assertSame(4096, $info->sizeBytes);
    }

    public function testInfiniteOrNegativeDurationBecomesNull(): void
    {
        foreach (['1e999', '-5'] as $duration) {
            $data = json_decode((string) file_get_contents($this->path('multitrack.json')), true, 512, \JSON_THROW_ON_ERROR);
            $data['format']['duration'] = $duration;

            $info = (new FfprobeOutputMapper())->map(json_encode($data, \JSON_THROW_ON_ERROR), 'probe-id');

            self::assertNull($info->durationSeconds);
        }
    }

    public function testFractionalFrameRateBecomesFloat(): void
    {
        $rate = $this->mapFixture('attachments.json')->video[0]->frameRate;

        self::assertIsFloat($rate);
        self::assertEqualsWithDelta(23.976, $rate, 0.001);
        self::assertSame(25.0, $this->mapFixture('multitrack.json')->video[0]->frameRate);
    }

    public function testNumericStringsBecomeIntegers(): void
    {
        $info = $this->mapFixture('attachments.json');

        self::assertSame(4500000, $info->video[0]->bitRate);
        self::assertSame(48000, $info->audio[0]->sampleRate);
        self::assertSame(734003200, $info->sizeBytes);
    }

    public function testDispositionBecomesBoolean(): void
    {
        $info = $this->mapFixture('multitrack.json');

        self::assertTrue($info->subtitles[0]->isDefault);
        self::assertFalse($info->subtitles[0]->isForced);
        self::assertFalse($info->subtitles[1]->isDefault);
        self::assertTrue($info->subtitles[1]->isForced);
        self::assertTrue($info->audio[0]->isDefault);
    }

    public function testSizeBytesIsTakenFromFormatSize(): void
    {
        self::assertSame(1000, $this->mapFixture('multitrack.json')->sizeBytes);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(FfprobeOutputException::class);

        $this->mapFixture('truncated.json');
    }

    #[DataProvider('provideNonObjectPayloads')]
    public function testNonObjectPayloadIsRejected(string $json): void
    {
        $this->expectException(FfprobeOutputException::class);

        (new FfprobeOutputMapper())->map($json, 'probe-id');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonObjectPayloads(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => ['null'];
        yield 'scalar' => ['42'];
        yield 'empty object' => ['{}'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('provideMissingRequiredFields')]
    public function testMissingRequiredFieldIsRejected(callable $break): void
    {
        $data = json_decode((string) file_get_contents($this->path('multitrack.json')), true, 512, \JSON_THROW_ON_ERROR);

        $this->expectException(FfprobeOutputException::class);

        (new FfprobeOutputMapper())->map(json_encode($break($data), \JSON_THROW_ON_ERROR), 'probe-id');
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function provideMissingRequiredFields(): iterable
    {
        yield 'format.format_name' => [static function (array $d): array {
            unset($d['format']['format_name']);

            return $d;
        }];
        yield 'format.size' => [static function (array $d): array {
            unset($d['format']['size']);

            return $d;
        }];
        yield 'format.size negative' => [static function (array $d): array {
            $d['format']['size'] = '-1';

            return $d;
        }];
        yield 'format.size overflow' => [static function (array $d): array {
            $d['format']['size'] = '99999999999999999999';

            return $d;
        }];
        yield 'streams[].index negative' => [static function (array $d): array {
            $d['streams'][2]['index'] = -1;

            return $d;
        }];
        yield 'streams' => [static function (array $d): array {
            unset($d['streams']);

            return $d;
        }];
        yield 'streams[].index' => [static function (array $d): array {
            unset($d['streams'][2]['index']);

            return $d;
        }];
        yield 'streams[].codec_type' => [static function (array $d): array {
            unset($d['streams'][2]['codec_type']);

            return $d;
        }];
    }

    private function mapFixture(string $name): MediaInfo
    {
        return (new FfprobeOutputMapper())->map((string) file_get_contents($this->path($name)), 'probe-id');
    }

    private function path(string $name): string
    {
        return __DIR__.'/../../../Fixtures/Ffprobe/'.$name;
    }
}
