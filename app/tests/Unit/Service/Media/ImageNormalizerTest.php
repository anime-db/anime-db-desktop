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

use App\Service\Media\ImageNormalizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs real GD encode/decode round-trips rather than mocking the `gd` extension: the behaviors
 * under test (the RIFF/WEBP signature check, alpha survival through a rescale, the container's
 * side limit) are properties of the actual codec, not of this service's control flow, so a mock
 * would only prove the mock's own scripted answers agree with themselves.
 *
 * Marked {@see Group} `runtime-parity` because several of these behaviors are documented in the
 * service's docblock as verified against one specific libgd/libwebp build (GD 2.3.3, this
 * environment) and are expected to, in principle, vary with the build the application ships —
 * see {@see ImageNormalizer}.
 */
#[Group('runtime-parity')]
final class ImageNormalizerTest extends TestCase
{
    private ImageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ImageNormalizer();
    }

    public function testNormalizeConvertsJpegToWebp(): void
    {
        $result = $this->normalizer->normalize(self::jpeg(50, 50));

        self::assertNotNull($result);
        self::assertWebpSignature($result);
    }

    public function testNormalizeReEncodesAnAlreadyWebpInputAndBytesDiffer(): void
    {
        $input = self::webp(32, 32, 100);

        $result = $this->normalizer->normalize($input);

        self::assertNotNull($result);
        self::assertWebpSignature($result);
        self::assertNotSame($input, $result, 'a re-encode at the fixed pipeline quality must actually run, not pass the input through');
    }

    public function testNormalizePreservesAlphaOfAPalettePngWithTransparency(): void
    {
        $result = $this->normalizer->normalize(self::palettePngWithTransparency(10, 10));

        self::assertNotNull($result);
        $decoded = imagecreatefromstring($result);
        self::assertNotFalse($decoded);
        imagealphablending($decoded, false);

        self::assertSame(127, self::alphaAt($decoded, 0, 0), 'the transparent pixel must stay fully transparent');
        self::assertSame(0, self::alphaAt($decoded, 5, 5), 'the opaque pixel must stay fully opaque');
    }

    public function testNormalizePreservesAlphaOfAPalettePngWithTransparencyWhenScaledDown(): void
    {
        $result = $this->normalizer->normalize(self::palettePngWithTransparency(20_000, 4));

        self::assertNotNull($result);
        $decoded = imagecreatefromstring($result);
        self::assertNotFalse($decoded);

        self::assertLessThanOrEqual(16_383, imagesx($decoded));
        self::assertLessThanOrEqual(16_383, imagesy($decoded));

        imagealphablending($decoded, false);
        self::assertSame(127, self::alphaAt($decoded, 0, 0), 'the transparent pixel must stay fully transparent after the rescale');
        self::assertSame(0, self::alphaAt($decoded, imagesx($decoded) - 1, 0), 'the opaque pixel must stay fully opaque after the rescale');
    }

    public function testNormalizeFitsAWideStripIntoTheContainerSideLimit(): void
    {
        $result = $this->normalizer->normalize(self::solidPng(16_384, 4));

        self::assertNotNull($result);
        self::assertNotSame('', $result, 'imagewebp() silently writes zero bytes past the container side limit unless the input was rescaled first');
        self::assertWebpSignature($result);
    }

    public function testNormalizeDoesNotCrashOnADegenerateAspectRatio(): void
    {
        $result = $this->normalizer->normalize(self::solidPng(50_000, 1));

        self::assertNotNull($result);
        $decoded = imagecreatefromstring($result);
        self::assertNotFalse($decoded);
        self::assertGreaterThanOrEqual(1, imagesy($decoded), 'rounding the minor side down to 0 must be floored at 1');
    }

    public function testNormalizeRejectsAPngHeaderWithNoPixelData(): void
    {
        // A crafted PNG header declaring a plausible area (below the area gate, so the gate
        // lets it through) but with no real pixel data: the decoder itself must fail on this
        // input — see testNormalizeRejectsWhenDeclaredAreaExceedsAConfiguredThreshold() for the
        // area gate.
        $result = $this->normalizer->normalize(self::pngHeaderOnly(4_000, 4_000));

        self::assertNull($result);
    }

    public function testNormalizeRejectsWhenDeclaredAreaExceedsAConfiguredThreshold(): void
    {
        $normalizer = new ImageNormalizer(maxAreaPixels: 100);

        // A real, decodable image above the lowered threshold: proves the rejection comes from
        // the area gate itself, not from the decoder failing on the input.
        $result = $normalizer->normalize(self::solidPng(20, 20));

        self::assertNull($result);
    }

    public function testNormalizeConvertsAnAnimatedGifUsingItsFirstFrame(): void
    {
        $result = $this->normalizer->normalize(self::animatedGif());

        self::assertNotNull($result);
        self::assertWebpSignature($result);
    }

    public function testNormalizeRejectsAnAnimatedWebpInput(): void
    {
        $bytes = file_get_contents(__DIR__.'/../../../Fixtures/Media/animated.webp');
        self::assertNotFalse($bytes);

        $result = $this->normalizer->normalize($bytes);

        // Documented, not guaranteed for every libgd/libwebp build — see the class docblock
        // of App\Service\Media\ImageNormalizer.
        self::assertNull($result);
    }

    public function testNormalizeRejectsSvg(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

        self::assertNull($this->normalizer->normalize($svg));
    }

    public function testNormalizeRejectsATruncatedJpeg(): void
    {
        $full = self::jpeg(50, 50);
        $truncated = substr($full, 0, (int) (\strlen($full) / 2));

        self::assertNull($this->normalizer->normalize($truncated));
    }

    public function testNormalizeRejectsArbitraryNonImageBytes(): void
    {
        self::assertNull($this->normalizer->normalize(random_bytes(200)));
    }

    public function testNormalizeStripsATrailingPayloadAppendedToAValidPng(): void
    {
        $polyglot = self::solidPng(20, 20).'<script>alert(1)</script>';

        $result = $this->normalizer->normalize($polyglot);

        self::assertNotNull($result);
        self::assertStringNotContainsString('<script>', $result);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; ++$x) {
            for ($y = 0; $y < $height; ++$y) {
                imagesetpixel($image, $x, $y, self::rgb($image, ($x * 5) % 256, ($y * 5) % 256, ($x + $y) % 256));
            }
        }

        return self::capture($image, static fn (\GdImage $image) => imagejpeg($image, quality: 90));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function webp(int $width, int $height, int $quality): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; ++$x) {
            for ($y = 0; $y < $height; ++$y) {
                imagesetpixel($image, $x, $y, self::rgb($image, ($x * 7) % 256, ($y * 13) % 256, abs($x - $y) % 256));
            }
        }

        return self::capture($image, static fn (\GdImage $image) => imagewebp($image, quality: $quality));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function solidPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, self::rgb($image, 10, 10, 10));

        return self::capture($image, static fn (\GdImage $image) => imagepng($image));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function palettePngWithTransparency(int $width, int $height): string
    {
        $image = imagecreate($width, $height);
        $transparent = self::rgba($image, 10, 20, 30, 127);
        imagefill($image, 0, 0, $transparent);
        $opaque = self::rgba($image, 200, 100, 50, 0);
        // A solid block, not a single pixel: imagescale()'s resampling filter blends
        // neighbouring pixels, so a lone opaque dot in a downscaled image can dissolve into
        // its transparent surroundings even though alpha itself is preserved correctly.
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, $opaque);

        return self::capture($image, static fn (\GdImage $image) => imagepng($image));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function pngHeaderOnly(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        $signature = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('N', $width).pack('N', $height)."\x08\x02\x00\x00\x00";

        return $signature.$chunk('IHDR', $ihdr).$chunk('IEND', '');
    }

    private static function animatedGif(): string
    {
        $firstFrame = self::gifFrame(255, 0, 0);
        $secondFrame = self::gifFrame(0, 0, 255);

        // Each GD-encoded single-frame GIF already contains a valid image descriptor + LZW data
        // block; splicing those blocks behind one shared header plus a looping application
        // extension turns them into a real multi-frame animated GIF without hand-rolling LZW.
        $imageDescriptorStart = strpos($firstFrame, "\x2C");
        self::assertIsInt($imageDescriptorStart);
        $imageBlock1 = substr($firstFrame, $imageDescriptorStart, \strlen($firstFrame) - $imageDescriptorStart - 1);

        $imageDescriptorStart2 = strpos($secondFrame, "\x2C");
        self::assertIsInt($imageDescriptorStart2);
        $imageBlock2 = substr($secondFrame, $imageDescriptorStart2, \strlen($secondFrame) - $imageDescriptorStart2 - 1);

        $header = substr($firstFrame, 0, $imageDescriptorStart);
        $loopingApplicationExtension = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        return $header.$loopingApplicationExtension
            .self::gifGraphicControlExtension(50).$imageBlock1
            .self::gifGraphicControlExtension(50).$imageBlock2
            ."\x3B";
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    private static function gifFrame(int $red, int $green, int $blue): string
    {
        $image = imagecreate(2, 2);
        self::rgb($image, $red, $green, $blue);

        return self::capture($image, static fn (\GdImage $image) => imagegif($image));
    }

    private static function gifGraphicControlExtension(int $delayCentiseconds): string
    {
        return "\x21\xF9\x04\x00".pack('v', $delayCentiseconds)."\x00\x00";
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    private static function rgb(\GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, $red, $green, $blue);
        if ($color === false) {
            throw new \RuntimeException('imagecolorallocate() unexpectedly failed to allocate a color.');
        }

        return $color;
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     * @param int<0, 127> $alpha
     */
    private static function rgba(\GdImage $image, int $red, int $green, int $blue, int $alpha): int
    {
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
        if ($color === false) {
            throw new \RuntimeException('imagecolorallocatealpha() unexpectedly failed to allocate a color.');
        }

        return $color;
    }

    /**
     * @param callable(\GdImage): bool $encoder
     */
    private static function capture(\GdImage $image, callable $encoder): string
    {
        ob_start();
        $encoder($image);
        $bytes = ob_get_clean();
        if ($bytes === false) {
            throw new \RuntimeException('ob_get_clean() unexpectedly returned false.');
        }

        return $bytes;
    }

    private static function alphaAt(\GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 24) & 0xFF;
    }

    private static function assertWebpSignature(string $bytes): void
    {
        self::assertGreaterThanOrEqual(12, \strlen($bytes));
        self::assertStringStartsWith('RIFF', $bytes);
        self::assertSame('WEBP', substr($bytes, 8, 4));
    }
}
