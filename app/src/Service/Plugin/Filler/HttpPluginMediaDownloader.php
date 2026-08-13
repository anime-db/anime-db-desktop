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

    /** Followed manually (not via the client's own redirect handling) so each hop can be re-validated against SSRF. */
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $mediaDir,
    ) {
    }

    public function download(int $animeId, string $url): ?string
    {
        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$animeId;
        $filename = sha1($url).$this->guessExtension($url);
        $targetPath = $targetDir.'/'.$filename;

        if (is_file($targetPath)) {
            return $filename;
        }

        $content = $this->fetch($url);
        if ($content === null || $content === '') {
            return null;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            return null;
        }

        if (file_put_contents($targetPath, $content) === false) {
            return null;
        }

        return $filename;
    }

    private function fetch(string $url): ?string
    {
        try {
            for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; ++$redirect) {
                if (!$this->isUrlAllowed($url)) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $url, ['max_redirects' => 0]);
                $statusCode = $response->getStatusCode();

                if (\in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    // Relative Location headers are rejected rather than resolved against $url,
                    // so every hop we follow has already gone through isUrlAllowed() as an absolute URL.
                    if ($location === null || !\in_array(strtolower((string) (parse_url($location, PHP_URL_SCHEME) ?? '')), ['http', 'https'], true)) {
                        return null;
                    }

                    $url = $location;

                    continue;
                }

                if ($statusCode !== 200) {
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
            }

            return null;
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /** Blocks anything but plain http(s) to a public host, so a plugin (or a plugin-relayed API response) can't be used to probe internal/link-local infrastructure. */
    private function isUrlAllowed(string $url): bool
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function guessExtension(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return \in_array($extension, self::ALLOWED_EXTENSIONS, true) ? '.'.$extension : '.jpg';
    }
}
