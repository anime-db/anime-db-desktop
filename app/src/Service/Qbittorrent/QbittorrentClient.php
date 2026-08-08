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

namespace App\Service\Qbittorrent;

use App\Service\Exception\QbittorrentClientException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around the qbittorrent-nox sidecar's WebUI API (native/supervisor/qbittorrent.js,
 * issue #345) — just enough to add/inspect/pause/resume torrents and read/write app preferences.
 * DownloadService (a separate, follow-up issue) is the intended caller.
 *
 * No login/session handling: the sidecar's WebUI listens on loopback only and is started with
 * "WebUI\LocalHostAuth=false" (see qbittorrent.js's seedConfig()), so requests from this process
 * bypass qBittorrent's own auth check entirely — there is no cookie/CSRF dance to do here.
 */
final class QbittorrentClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {
    }

    public function addTorrentFromMagnet(string $magnetUri, ?string $savePath = null): void
    {
        $fields = ['urls' => $magnetUri];
        if ($savePath !== null) {
            $fields['savepath'] = $savePath;
        }

        $this->request('POST', '/api/v2/torrents/add', ['body' => $fields]);
    }

    public function addTorrentFromFile(string $filename, string $content, ?string $savePath = null): void
    {
        $boundary = bin2hex(random_bytes(16));
        $fields = [];
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
    public function getTorrentsInfo(?string $hash = null): array
    {
        $query = $hash !== null ? ['hashes' => $hash] : [];

        return $this->requestJsonList('GET', '/api/v2/torrents/info', ['query' => $query]);
    }

    public function pause(string $hash): void
    {
        $this->request('POST', '/api/v2/torrents/pause', ['body' => ['hashes' => $hash]]);
    }

    public function resume(string $hash): void
    {
        $this->request('POST', '/api/v2/torrents/resume', ['body' => ['hashes' => $hash]]);
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
