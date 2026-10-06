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

namespace App\Service\Qbittorrent;

use App\Service\Exception\QbittorrentClientException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around the qbittorrent-nox sidecar's WebUI API (native/supervisor/qbittorrent.js,
 * issue #345) — just enough to add/inspect/stop/start torrents and read/write app preferences.
 * DownloadService (a separate, follow-up issue) is the intended caller.
 *
 * No login/session handling: the sidecar's WebUI listens on loopback only and is started with
 * "WebUI\LocalHostAuth=false" (see qbittorrent.js's seedConfig()), so requests from this process
 * bypass qBittorrent's own auth check entirely — there is no cookie/CSRF dance to do here.
 */
final class QbittorrentClient
{
    /**
     * Tag applied to every torrent this app adds (issue #843). Marks the torrent as
     * added by this app; {@see \App\Service\Download\DownloadCompletionPoller} does not filter
     * by it, since a torrent may be added by hand or have its tag removed.
     */
    public const string TAG = 'animedb';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {
    }

    public function addTorrentFromMagnet(string $magnetUri, ?string $savePath = null): void
    {
        // contentLayout=Original (issue #852) pins qBittorrent to the torrent's own layout —
        // without it, qBittorrent's "Torrent content layout" preference can flatten/wrap a
        // torrent's files, moving the content_path DownloadCompletionPoller later relies on to
        // derive the incoming-relocation and linking target out from under what this app expects.
        $fields = ['urls' => $magnetUri, 'tags' => self::TAG, 'contentLayout' => 'Original'];
        if ($savePath !== null) {
            $fields['savepath'] = $savePath;
        }

        $this->request('POST', '/api/v2/torrents/add', ['body' => $fields]);
    }

    public function addTorrentFromFile(string $filename, string $content, ?string $savePath = null): void
    {
        $boundary = bin2hex(random_bytes(16));
        // contentLayout=Original — see addTorrentFromMagnet() for why.
        $fields = ['tags' => self::TAG, 'contentLayout' => 'Original'];
        if ($savePath !== null) {
            $fields['savepath'] = $savePath;
        }

        $this->request('POST', '/api/v2/torrents/add', [
            'body' => $this->buildMultipartBody($fields, $filename, $content, $boundary),
            'headers' => ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTorrentsInfo(?string $tag = null): array
    {
        $query = $tag !== null ? ['tag' => $tag] : [];

        return $this->requestJsonList('GET', '/api/v2/torrents/info', ['query' => $query]);
    }

    public function stop(string $hash): void
    {
        $this->request('POST', '/api/v2/torrents/stop', ['body' => ['hashes' => $hash]]);
    }

    public function start(string $hash): void
    {
        $this->request('POST', '/api/v2/torrents/start', ['body' => ['hashes' => $hash]]);
    }

    /**
     * Removes a torrent from qBittorrent (issue #856). $deleteFiles controls whether its
     * downloaded data is removed from disk too — this is the ONLY place in the whole app that can
     * ever have that effect; no PHP code here or anywhere else in App\Service\Download deletes
     * files or directories directly.
     */
    public function delete(string $hash, bool $deleteFiles): void
    {
        $this->request('POST', '/api/v2/torrents/delete', [
            'body' => ['hashes' => $hash, 'deleteFiles' => $deleteFiles ? 'true' : 'false'],
        ]);
    }

    public function setSavePath(string $hash, string $savePath): void
    {
        $this->request('POST', '/api/v2/torrents/setLocation', ['body' => ['hashes' => $hash, 'location' => $savePath]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferences(): array
    {
        return $this->requestJson('GET', '/api/v2/app/preferences');
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function setPreferences(array $preferences): void
    {
        $this->request('POST', '/api/v2/app/setPreferences', [
            'body' => ['json' => json_encode($preferences, \JSON_THROW_ON_ERROR)],
        ]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function buildMultipartBody(array $fields, string $filename, string $content, string $boundary): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value."\r\n";
        }

        $safeFilename = str_replace(['"', "\r", "\n"], '_', $filename);
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"torrents\"; filename=\"{$safeFilename}\"\r\n";
        $body .= "Content-Type: application/x-bittorrent\r\n\r\n";
        $body .= $content."\r\n";
        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $options = []): array
    {
        $response = $this->request($method, $path, $options);

        try {
            return $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw new QbittorrentClientException(\sprintf('qbittorrent-nox WebUI response from "%s" was not valid JSON', $path), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    private function requestJsonList(string $method, string $path, array $options = []): array
    {
        $decoded = $this->requestJson($method, $path, $options);

        $result = [];
        foreach ($decoded as $item) {
            if (\is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        try {
            $response = $this->httpClient->request($method, $this->baseUrl.$path, $options);
            $statusCode = $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new QbittorrentClientException(\sprintf('qbittorrent-nox WebUI request to "%s" failed: %s', $path, $exception->getMessage()), previous: $exception);
        }

        if ($statusCode >= 400) {
            throw new QbittorrentClientException(\sprintf('qbittorrent-nox WebUI request to "%s" returned HTTP %d', $path, $statusCode));
        }

        return $response;
    }
}
