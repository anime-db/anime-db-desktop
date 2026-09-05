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

use App\Service\Media\ImageNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The filename is a deterministic sha1() of the source URL, not a random/incrementing one: a
 * plugin re-supplying the same image URL (e.g. the same cover reapplied on a later merge, or the
 * same gallery URL appearing again) must resolve to the same local file, so PluginAnimeDataMerger
 * can recognise it as already present instead of downloading and storing a duplicate.
 *
 * The extension is always `.webp`, not derived from the URL or the response's Content-Type: every
 * downloaded body is re-encoded by {@see ImageNormalizer} before it reaches disk, so the file on
 * disk is always a WebP image regardless of what the source served.
 */
final class HttpPluginMediaDownloader implements PluginMediaDownloaderInterface
{
    /** Guards against a plugin-supplied URL pointing at an unreasonably large response body. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Followed manually (not via the client's own redirect handling) so each hop can be re-validated against SSRF. */
    private const MAX_REDIRECTS = 5;

    /** Not covered by FILTER_FLAG_NO_PRIV_RANGE: carrier-grade NAT space (RFC 6598), routable only within an ISP's own network. */
    private const CGNAT_RANGE_FIRST = 1681915904; // ip2long('100.64.0.0')
    private const CGNAT_RANGE_LAST = 1685587967; // ip2long('100.127.255.255')

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ImageNormalizer $imageNormalizer,
        private readonly LoggerInterface $logger,
        private readonly HostResolverInterface $hostResolver,
        private readonly string $mediaDir,
    ) {
    }

    public function download(int $animeId, string $url): ?string
    {
        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$animeId;
        $filename = sha1($url).'.webp';
        $targetPath = $targetDir.'/'.$filename;

        if (is_file($targetPath)) {
            return $filename;
        }

        $content = $this->fetch($url);
        if ($content === null || $content === '') {
            return null;
        }

        $normalized = $this->imageNormalizer->normalize($content);
        if ($normalized === null) {
            $this->logger->warning('Discarding a plugin-supplied media URL: the response body could not be normalized into a WebP image.', [
                'url' => $url,
            ]);

            return null;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            $this->logger->warning('Discarding a plugin-supplied media URL: the media directory for this anime could not be created.', [
                'url' => $url,
                'directory' => $targetDir,
            ]);

            return null;
        }

        if (!$this->writeAtomically($targetDir, $targetPath, $normalized)) {
            $this->logger->warning('Discarding a plugin-supplied media URL: the normalized image could not be written to disk.', [
                'url' => $url,
                'path' => $targetPath,
            ]);

            return null;
        }

        return $filename;
    }

    /**
     * Writes through a temporary file in the same directory as $targetPath, then rename()s it
     * into place, so a process interrupted mid-write never leaves a truncated file under the
     * final name for the early is_file() check above to mistake for a complete download.
     */
    private function writeAtomically(string $targetDir, string $targetPath, string $content): bool
    {
        $tmpPath = tempnam($targetDir, 'tmp-');
        if ($tmpPath === false) {
            return false;
        }

        // tempnam() does not fail when $targetDir is not writable — it silently falls back to the
        // system temp directory. A file created there would turn the rename() below into a
        // cross-device move: not atomic, and on Linux not even permitted. The fallback is rejected
        // here rather than discovered as a rename() failure, so "the temporary file always sits
        // next to its target" stays an invariant of this method instead of a likely outcome.
        $tmpDir = realpath(\dirname($tmpPath));
        if ($tmpDir === false || $tmpDir !== realpath($targetDir)) {
            @unlink($tmpPath);

            return false;
        }

        if (file_put_contents($tmpPath, $content) === false) {
            @unlink($tmpPath);

            return false;
        }

        if (!rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            return false;
        }

        return true;
    }

    private function fetch(string $url): ?string
    {
        try {
            for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; ++$redirect) {
                $ip = $this->checkedIp($url);
                if ($ip === null) {
                    return null;
                }

                // Pins the request to the exact address checkedIp() just validated, so the
                // client can't resolve $url's host a second time and get a different answer
                // (DNS rebinding) between the check above and the request below.
                $response = $this->httpClient->request('GET', $url, [
                    'max_redirects' => 0,
                    'resolve' => [(string) parse_url($url, PHP_URL_HOST) => $ip],
                ]);
                $statusCode = $response->getStatusCode();

                if (\in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    // Relative Location headers are rejected rather than resolved against $url,
                    // so every hop we follow has already gone through checkedIp() as an absolute URL.
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

    /**
     * Blocks anything but plain http(s) to a public host, so a plugin (or a plugin-relayed API
     * response) can't be used to probe internal/link-local infrastructure.
     *
     * @return string|null the single address $url's host was validated against, to pin the
     *                     request to, or null if $url must not be fetched
     */
    private function checkedIp(string $url): ?string
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->hostResolver->resolve($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null;
            }
        }

        // gethostbynamel() itself resolved $host to just one of these addresses when fetch()
        // made its own request in the pre-fix code; picking the first one here reproduces that,
        // now as a value the caller can pin the request to instead of leaving it to resolve again.
        return $ips[0];
    }

    private function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $long = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($ip) : false;

        return $long === false || $long < self::CGNAT_RANGE_FIRST || $long > self::CGNAT_RANGE_LAST;
    }
}
