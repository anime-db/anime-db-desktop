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

namespace App\Tests\Unit\Service\Qbittorrent;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use App\Service\Exception\TorrentProxyApplyException;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Qbittorrent\TorrentProxySynchronizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TorrentProxySynchronizerTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:18080';

    public function testSocks5AppliesFailClosedPauseApplyConfirmResumeInOrder(): void
    {
        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $url;

            if (str_ends_with($url, '/api/v2/app/preferences')) {
                return new MockResponse(json_encode($this->realQbittorrentPreferencesResponse(), \JSON_THROW_ON_ERROR), [
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));
        $synchronizer->apply($this->socks5Settings());

        $this->assertSame([
            self::BASE_URL.'/api/v2/torrents/pause',
            self::BASE_URL.'/api/v2/app/setPreferences',
            self::BASE_URL.'/api/v2/app/preferences',
            self::BASE_URL.'/api/v2/torrents/resume',
        ], $calls);
    }

    public function testSocks5PausesAllTorrentsAndSendsDnsLeakGuardsAndCredentials(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            if (str_ends_with($url, '/api/v2/torrents/pause') || str_ends_with($url, '/api/v2/torrents/resume')) {
                $captured['hashes_'.basename($url)] = $options['body'];
            }
            if (str_ends_with($url, '/api/v2/app/setPreferences')) {
                $captured['preferences'] = $options['body'];
            }
            if (str_ends_with($url, '/api/v2/app/preferences')) {
                return new MockResponse(json_encode($this->realQbittorrentPreferencesResponse(true), \JSON_THROW_ON_ERROR), [
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));
        $synchronizer->apply($this->socks5Settings(withAuth: true));

        $this->assertSame('hashes=all', $captured['hashes_pause']);
        $this->assertSame('hashes=all', $captured['hashes_resume']);

        $sentPreferences = json_decode(
            urldecode(substr($captured['preferences'], \strlen('json='))),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $this->assertSame('SOCKS5', $sentPreferences['proxy_type']);
        $this->assertSame('proxy.example', $sentPreferences['proxy_ip']);
        $this->assertSame(1080, $sentPreferences['proxy_port']);
        $this->assertTrue($sentPreferences['proxy_hostname_lookup']);
        $this->assertTrue($sentPreferences['proxy_peer_connections']);
        $this->assertTrue($sentPreferences['proxy_bittorrent']);
        $this->assertTrue($sentPreferences['proxy_auth_enabled']);
        $this->assertSame('alice', $sentPreferences['proxy_username']);
        $this->assertSame('p4ss', $sentPreferences['proxy_password']);
    }

    public function testSocks5LeavesTorrentsPausedAndThrowsWhenApplyCallFails(): void
    {
        $resumeCalled = false;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$resumeCalled): MockResponse {
            if (str_ends_with($url, '/api/v2/torrents/resume')) {
                $resumeCalled = true;
            }
            if (str_ends_with($url, '/api/v2/app/setPreferences')) {
                throw new TransportException('Connection refused');
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));

        $this->expectException(TorrentProxyApplyException::class);
        try {
            $synchronizer->apply($this->socks5Settings());
        } finally {
            $this->assertFalse($resumeCalled, 'resume() must not be called when the apply call itself fails.');
        }
    }

    /**
     * Confirms the "verify the route, not just reachability" requirement (issue #347): even a
     * successful setPreferences() call is not enough — if the readback doesn't match what was
     * requested, torrents must stay paused and a visible error must be thrown.
     */
    public function testSocks5LeavesTorrentsPausedAndThrowsWhenReadbackDoesNotConfirmProxyType(): void
    {
        $resumeCalled = false;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$resumeCalled): MockResponse {
            if (str_ends_with($url, '/api/v2/torrents/resume')) {
                $resumeCalled = true;
            }
            if (str_ends_with($url, '/api/v2/app/preferences')) {
                // qbittorrent-nox silently kept the proxy off despite the setPreferences() call.
                return new MockResponse(json_encode(['proxy_type' => 'None'], \JSON_THROW_ON_ERROR), [
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));

        $this->expectException(TorrentProxyApplyException::class);
        try {
            $synchronizer->apply($this->socks5Settings());
        } finally {
            $this->assertFalse($resumeCalled, 'resume() must not be called when the applied proxy is not confirmed.');
        }
    }

    /**
     * A partial DNS-leak-guard failure (proxy_type confirmed, but proxy_hostname_lookup silently
     * not applied) must be treated the same as a total failure — never a silent, half-proxied
     * state.
     */
    public function testSocks5LeavesTorrentsPausedWhenOnlyDnsLeakGuardFailsToConfirm(): void
    {
        $resumeCalled = false;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$resumeCalled): MockResponse {
            if (str_ends_with($url, '/api/v2/torrents/resume')) {
                $resumeCalled = true;
            }
            if (str_ends_with($url, '/api/v2/app/preferences')) {
                $preferences = $this->realQbittorrentPreferencesResponse();
                $preferences['proxy_hostname_lookup'] = false;

                return new MockResponse(json_encode($preferences, \JSON_THROW_ON_ERROR), [
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));

        $this->expectException(TorrentProxyApplyException::class);
        try {
            $synchronizer->apply($this->socks5Settings());
        } finally {
            $this->assertFalse($resumeCalled);
        }
    }

    public function testSocks5LeavesTorrentsPausedWhenPauseAllItselfFails(): void
    {
        $setPreferencesCalled = false;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$setPreferencesCalled): MockResponse {
            if (str_ends_with($url, '/api/v2/app/setPreferences')) {
                $setPreferencesCalled = true;
            }
            if (str_ends_with($url, '/api/v2/torrents/pause')) {
                throw new TransportException('Connection refused');
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));

        $this->expectException(TorrentProxyApplyException::class);
        try {
            $synchronizer->apply($this->socks5Settings());
        } finally {
            $this->assertFalse($setPreferencesCalled, 'The proxy must never be applied if the fail-closed pause could not be confirmed to have been sent.');
        }
    }

    public function testHttpProtocolAppliesDirectPreferencesWithoutPausingAnything(): void
    {
        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $url;
            if (str_ends_with($url, '/api/v2/app/setPreferences')) {
                $decoded = json_decode(urldecode(substr($options['body'], \strlen('json='))), true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame('None', $decoded['proxy_type']);
                $this->assertFalse($decoded['proxy_hostname_lookup']);
                $this->assertFalse($decoded['proxy_peer_connections']);
                $this->assertFalse($decoded['proxy_bittorrent']);
            }

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.example', 3128);
        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));
        $synchronizer->apply($settings);

        $this->assertSame([self::BASE_URL.'/api/v2/app/setPreferences'], $calls, 'HTTP proxy must run torrent traffic direct — no pause/resume, no proxy applied to qbittorrent-nox.');
    }

    public function testNoProxyAppliesDirectPreferencesWithoutPausingAnything(): void
    {
        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('Ok.');
        }, self::BASE_URL);

        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Socks5, null, null);
        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));
        $synchronizer->apply($settings);

        $this->assertSame([self::BASE_URL.'/api/v2/app/setPreferences'], $calls);
    }

    private function socks5Settings(bool $withAuth = false): ProxySettings
    {
        return new ProxySettings(
            ProxyMode::Manual,
            ProxyProtocol::Socks5,
            'proxy.example',
            1080,
            $withAuth ? 'alice' : null,
            $withAuth ? 'p4ss' : null,
        );
    }

    /**
     * A representative slice of qBittorrent 5.2.3's real `GET /api/v2/app/preferences` response —
     * field names are hand-typed from the WebUI API source (src/webui/api/appcontroller.cpp at
     * tag release-5.2.3), independently of TorrentProxySynchronizer's own constants. If the
     * synchronizer's CONFIRMED_KEYS/socks5Preferences() ever regress to the pre-5.0 names
     * (proxy_hostnames, proxy_tracker_connections), this fixture keeps returning the real 5.x
     * names and confirmApplied() fails the test — instead of the mock silently mirroring
     * whatever the production code happens to send.
     *
     * @return array<string, mixed>
     */
    private function realQbittorrentPreferencesResponse(bool $withAuth = false): array
    {
        return [
            'proxy_type' => 'SOCKS5',
            'proxy_ip' => 'proxy.example',
            'proxy_port' => 1080,
            'proxy_auth_enabled' => $withAuth,
            'proxy_username' => $withAuth ? 'alice' : '',
            'proxy_password' => $withAuth ? 'p4ss' : '',
            'proxy_hostname_lookup' => true,
            'proxy_bittorrent' => true,
            'proxy_peer_connections' => true,
            'proxy_rss' => false,
            'proxy_misc' => false,
        ];
    }
}
