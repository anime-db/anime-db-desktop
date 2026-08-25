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

/**
 * Re-encodes arbitrary image bytes into WebP so that a file's declared type and its actual
 * content always agree, regardless of who produced the original bytes. There is no passthrough
 * for any input format, including one that already is WebP: a byte-for-byte copy would let a
 * polyglot (bytes that are simultaneously a valid image and something else entirely) survive
 * unmodified, which defeats the point of normalizing in the first place.
 *
 * Deliberately dependency-free: no network, no filesystem, no Doctrine. `normalize()` is a pure
 * bytes-in, bytes-out transform, so it composes with whatever eventually reads and writes the
 * bytes rather than owning that responsibility itself. `null` means "could not be normalized
 * safely" — callers must treat that as "no file", never as "keep the original bytes".
 *
 * The pipeline is exactly one decode, an optional single rescale, and one encode. A future
 * "resize for display" concern (a separate, deliberate feature) can slot its own scale step in
 * where {@see MAX_SIDE_PIXELS} scaling already happens, rather than bolting a second encode pass
 * on top of an image this service has already compressed once.
 *
 * Animation is not preserved, in either direction. `imagecreatefromstring()` reads only the
 * first frame of an animated GIF and has no API to detect that a GIF was animated at all, so an
 * animated GIF input produces a static WebP of its first frame — accepted as the cost of not
 * carrying an animation decoder. An animated WebP input behaves differently: libgd's WebP read
 * path uses libwebp's simple (non-animation) decoding API, which refuses a file carrying the
 * animation (`ANIM`) chunk outright, so `imagecreatefromstring()` returns `false` and
 * `normalize()` reports `null` for the whole file rather than extracting a frame. Confirmed by
 * {@see \App\Tests\Unit\Service\Media\ImageNormalizerTest::testNormalizeRejectsAnAnimatedWebpInput()}
 * against the GD build this test suite runs under; not a guarantee for every libgd/libwebp build.
 */
final class ImageNormalizer
{
    /**
     * Rejection threshold, checked from the format's declared dimensions before any decode.
     * Decoding is the only place a "declared" image turns into an actual bitmap, and libgd
     * allocates that bitmap outside the Zend memory manager — `memory_limit` does not apply to
     * it and a `try`/`catch` around the decode call cannot recover from it running out of RAM.
     * The only defense against a memory-exhaustion bomb is refusing to decode it at all.
     *
     * The threshold itself is sized as an input funnel, not as a target resolution: a cover image
     * is on the order of 1.5 Mpx, a 1080p frame around 2 Mpx, so any ordinary source clears it
     * with a wide margin. 24 Mpx (roughly 6000x4000) peaks at roughly 290 MB once decoded, because
     * `imagewebp()` builds its own RGBA buffer on top of the GD bitmap already in memory — call it
     * a threefold multiplier on the raw pixel count, not a single one.
     */
    private const int MAX_AREA_PIXELS = 24_000_000;

    /**
     * WebP's own container format cannot represent a side longer than this, independent of any
     * memory concern — it is a format limit, not a safety limit, so it is enforced by scaling
     * down after a successful decode rather than by refusing the input outright. This is what
     * catches the narrow-strip shape (e.g. 16384x4) that a pure area threshold would never flag:
     * such an image decodes instantly and stays well under {@see MAX_AREA_PIXELS}, but still does
     * not fit the container it is about to be written into.
     */
    private const int MAX_SIDE_PIXELS = 16_383;

    /**
     * `imagewebp()`'s lossy quality argument. 82 is a fixed sanitization-pipeline setting, not a
     * per-image tuning knob — this service converts everything it is given, so there is no caller
     * context to tune it from.
     */
    private const int WEBP_QUALITY = 82;

    public function __construct(private readonly int $maxAreaPixels = self::MAX_AREA_PIXELS)
    {
    }

    public function normalize(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        [$declaredWidth, $declaredHeight] = $info;
        if ($declaredWidth * $declaredHeight > $this->maxAreaPixels) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // The declared header dimensions are only trusted for the pre-decode area gate above;
        // the scaling decision must use what actually got decoded, since an untrusted input's
        // header is not guaranteed to match its real content.
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::MAX_SIDE_PIXELS || $height > self::MAX_SIDE_PIXELS) {
            $image = $this->scaleToFitSideLimit($image, $width, $height);
            if ($image === null) {
                return null;
            }
        }

        return $this->encodeToWebp($image);
    }

    private function scaleToFitSideLimit(\GdImage $image, int $width, int $height): ?\GdImage
    {
        $scale = self::MAX_SIDE_PIXELS / max($width, $height);
        $scaledWidth = max(1, (int) round($width * $scale));
        // Rounding the second side down to 0 is a real failure mode of `imagescale()`, not a
        // theoretical one: at an aspect ratio steep enough (e.g. 50000x1), `round()` on the
        // smaller side yields 0, and `imagescale()` refuses a 0px dimension outright.
        $scaledHeight = max(1, (int) round($height * $scale));

        $scaled = imagescale($image, $scaledWidth, $scaledHeight);
        if ($scaled === false) {
            return null;
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);

        return $scaled;
    }

    private function encodeToWebp(\GdImage $image): ?string
    {
        ob_start();
        @imagewebp($image, quality: self::WEBP_QUALITY);
        $webp = ob_get_clean();

        // imagewebp()'s return value is not trustworthy: past the container's side limit it
        // reports success while silently writing zero bytes, with the actual failure reaching
        // only a warning. The one reliable success signal is the buffer itself: non-empty and
        // starting with a RIFF/WEBP container signature.
        if ($webp === false || $webp === '' || !self::hasWebpSignature($webp)) {
            return null;
        }

        return $webp;
    }

    private static function hasWebpSignature(string $bytes): bool
    {
        return \strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP';
    }
}
