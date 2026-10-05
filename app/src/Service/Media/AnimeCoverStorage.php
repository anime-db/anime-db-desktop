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

use App\Entity\Anime;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps the cover file of an entry under `media/{id}/`, the directory the "fill from source"
 * download uses too. The name is the sha1() of the normalized WebP bytes, so the same picture
 * uploaded twice resolves to the same file.
 *
 * A file of an entry can be shared: the cover and a gallery image that came from one URL are the
 * same file (the downloader names files by sha1(url)). {@see self::releaseIfUnused()} is therefore
 * the only way a file leaves the disk, and it checks every reference first.
 */
final class AnimeCoverStorage
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    private const array ALLOWED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    public function __construct(
        private readonly ImageNormalizer $imageNormalizer,
        private readonly string $mediaDir,
    ) {
    }

    /**
     * Validates the upload on the server (size and real content, not the client's name or MIME
     * type) and re-encodes it into WebP. Nothing is written to disk.
     *
     * @throws CoverUploadException
     */
    public function prepare(UploadedFile $file): string
    {
        if ($file->getError() === \UPLOAD_ERR_INI_SIZE || $file->getError() === \UPLOAD_ERR_FORM_SIZE) {
            throw new CoverUploadException('anime_edit.error_cover_too_large');
        }
        if (!$file->isValid()) {
            throw new CoverUploadException('anime_edit.error_cover_upload');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new CoverUploadException('anime_edit.error_cover_too_large');
        }

        $bytes = @file_get_contents($file->getPathname());
        if ($bytes === false) {
            throw new CoverUploadException('anime_edit.error_cover_upload');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new CoverUploadException('anime_edit.error_cover_too_large');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !\in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new CoverUploadException('anime_edit.error_cover_invalid');
        }

        return $this->imageNormalizer->normalize($bytes) ?? throw new CoverUploadException('anime_edit.error_cover_invalid');
    }

    /**
     * @param string $webp bytes returned by {@see self::prepare()}
     *
     * @return string the file name relative to `media/{id}/`, the format of Anime::$cover
     *
     * @throws CoverUploadException
     */
    public function store(Anime $anime, string $webp): string
    {
        $animeId = $anime->id ?? throw new \LogicException('The entry must be persisted before its cover is stored.');
        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$animeId;
        $filename = sha1($webp).'.webp';
        $targetPath = $targetDir.'/'.$filename;

        if (is_file($targetPath)) {
            return $filename;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            throw new CoverUploadException('anime_edit.error_cover_save');
        }

        $tmpPath = tempnam($targetDir, 'tmp-');
        if ($tmpPath === false) {
            throw new CoverUploadException('anime_edit.error_cover_save');
        }
        // tempnam() silently falls back to the system temp dir when $targetDir is not writable,
        // which would turn the rename() below into a cross-device move.
        if (realpath(\dirname($tmpPath)) !== realpath($targetDir) || file_put_contents($tmpPath, $webp) === false || !rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            throw new CoverUploadException('anime_edit.error_cover_save');
        }

        return $filename;
    }

    /**
     * Deletes $filename of the entry unless the cover or a gallery image still points at it.
     * Call after the change of the cover has been flushed.
     */
    public function releaseIfUnused(Anime $anime, string $filename): void
    {
        // The value is a bare file name by contract; anything else is not ours to delete.
        if ($filename === '' || basename($filename) !== $filename || $anime->id === null) {
            return;
        }
        if ($anime->getCover() === $filename) {
            return;
        }
        foreach ($anime->getImages() as $image) {
            if ($image->source === $filename) {
                return;
            }
        }

        @unlink(rtrim($this->mediaDir, '/\\').'/'.$anime->id.'/'.$filename);
    }
}
