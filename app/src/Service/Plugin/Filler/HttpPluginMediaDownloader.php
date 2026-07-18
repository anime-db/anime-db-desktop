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

namespace App\Service\Plugin\Filler;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The filename is a deterministic sha1() of the source URL, not a random/incrementing one: a
 * plugin re-supplying the same image URL (e.g. the same cover reapplied on a later merge, or the
 * same gallery URL appearing again) must resolve to the same local file, so PluginAnimeDataMerger
 * can recognise it as already present instead of downloading and storing a duplicate.
 */
final class HttpPluginMediaDownloader implements PluginMediaDownloaderInterface
{
    /** Guards against a plugin-supplied URL pointing at an unreasonably large response body. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $mediaDir,
    ) {
    }

    public function download(int $animeId, string $url): ?string
    {
        $content = $this->fetch($url);
        if (null === $content || '' === $content) {
            return null;
        }

        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$animeId;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0o777, true) && !is_dir($targetDir)) {
            return null;
        }

        $filename = sha1($url).$this->guessExtension($url);
        if (false === file_put_contents($targetDir.'/'.$filename, $content)) {
            return null;
        }

        return $filename;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url);
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $content = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (\strlen($content) > self::MAX_BYTES) {
                    return null;
                }
            }

            return $content;
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function guessExtension(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return \in_array($extension, self::ALLOWED_EXTENSIONS, true) ? '.'.$extension : '.jpg';
    }
}
