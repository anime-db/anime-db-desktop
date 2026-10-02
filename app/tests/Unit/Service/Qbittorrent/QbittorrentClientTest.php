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

namespace App\Tests\Unit\Service\Qbittorrent;

use App\Service\Exception\QbittorrentClientException;
use App\Service\Qbittorrent\QbittorrentClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QbittorrentClientTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:18080';

    public function testAddTorrentFromMagnetPostsUrlsField(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse('Ok.');
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $client->addTorrentFromMagnet('magnet:?xt=urn:btih:abc', '/downloads/anime');

        self::assertIsArray($captured);
        [$method, $url, $options] = $captured;
        self::assertSame('POST', $method);
        self::assertSame(self::BASE_URL.'/api/v2/torrents/add', $url);
        self::assertSame('urls=magnet%3A%3Fxt%3Durn%3Abtih%3Aabc&savepath=%2Fdownloads%2Fanime', $options['body']);
    }

    public function testAddTorrentFromFileSendsMultipartBodyWithTorrentContent(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('Ok.');
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $client->addTorrentFromFile('example.torrent', 'raw-torrent-bytes', '/downloads/anime');

        self::assertIsArray($captured);
        self::assertStringContainsString('multipart/form-data; boundary=', $captured['normalized_headers']['content-type'][0]);
        self::assertStringContainsString('name="savepath"', $captured['body']);
        self::assertStringContainsString('/downloads/anime', $captured['body']);
        self::assertStringContainsString('filename="example.torrent"', $captured['body']);
        self::assertStringContainsString('raw-torrent-bytes', $captured['body']);
    }

    public function testGetTorrentsInfoDecodesJsonAndPassesHashFilter(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse(json_encode([['hash' => 'abc', 'name' => 'Some Anime']], \JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $result = $client->getTorrentsInfo('abc');

        self::assertIsArray($captured);
        self::assertSame('GET', $captured[0]);
        self::assertSame(['hashes' => 'abc'], $captured[2]['query']);
        self::assertSame([['hash' => 'abc', 'name' => 'Some Anime']], $result);
    }

    public function testStopAndStartPostHashes(): void
    {
        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options['body']];

            return new MockResponse('Ok.');
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $client->stop('abc');
        $client->start('abc');

        self::assertSame(['POST', self::BASE_URL.'/api/v2/torrents/stop', 'hashes=abc'], $calls[0]);
        self::assertSame(['POST', self::BASE_URL.'/api/v2/torrents/start', 'hashes=abc'], $calls[1]);
    }

    public function testSetSavePathPostsHashAndLocation(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['body'];

            return new MockResponse('Ok.');
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $client->setSavePath('abc', '/downloads/anime');

        self::assertSame('hashes=abc&location=%2Fdownloads%2Fanime', $captured);
    }

    public function testGetPreferencesDecodesJson(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode(['save_path' => '/downloads'], \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        self::assertSame(['save_path' => '/downloads'], $client->getPreferences());
    }

    public function testSetPreferencesPostsJsonEncodedField(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['body'];

            return new MockResponse('Ok.');
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $client->setPreferences(['dht' => true]);

        self::assertSame('json='.rawurlencode(json_encode(['dht' => true], \JSON_THROW_ON_ERROR)), $captured);
    }

    public function testTransportFailureIsWrappedInQbittorrentClientException(): void
    {
        $httpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused');
        });

        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $this->expectException(QbittorrentClientException::class);
        $client->getPreferences();
    }

    public function testHttpErrorStatusIsWrappedInQbittorrentClientException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404]));

        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $this->expectException(QbittorrentClientException::class);
        $client->stop('unknown-hash');
    }

    public function testInvalidJsonResponseIsWrappedInQbittorrentClientException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('not json', [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        $this->expectException(QbittorrentClientException::class);
        $client->getPreferences();
    }
}
