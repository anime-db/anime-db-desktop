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

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use App\Event\ProxySettingsChangedEvent;
use App\EventSubscriber\TorrentProxySubscriber;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Qbittorrent\TorrentProxySynchronizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TorrentProxySubscriberTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:18080';

    public function testSubscribesToProxySettingsChangedEvent(): void
    {
        $this->assertSame(
            ['App\Event\ProxySettingsChangedEvent' => 'onProxySettingsChanged'],
            TorrentProxySubscriber::getSubscribedEvents(),
        );
    }

    /**
     * TorrentProxySynchronizer is final and cannot be mocked (see ProxyControllerTest's
     * createController() for the same constraint on ProxyConfigProvider/ProxyTestService); a
     * real instance backed by a MockHttpClient stands in for it instead. SOCKS5 is used here
     * (rather than HTTP) specifically because the direct/HTTP path ignores the event's settings
     * content entirely (see TorrentProxySynchronizer::directPreferences()) — asserting only the
     * called URL there would prove a call happened, not that the event's host/port actually
     * reached qbittorrent-nox. The readback response below echoes the SOCKS5 settings back so the
     * synchronizer's fail-closed confirmation step succeeds.
     */
    public function testForwardsEventSettingsToSynchronizer(): void
    {
        $calls = [];
        $sentPreferences = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$sentPreferences): MockResponse {
            $calls[] = $url;

            if (str_ends_with($url, '/api/v2/app/setPreferences')) {
                $sentPreferences = json_decode(
                    urldecode(substr($options['body'], \strlen('json='))),
                    true,
                    flags: \JSON_THROW_ON_ERROR,
                );
            }

            if (str_ends_with($url, '/api/v2/app/preferences')) {
                return new MockResponse(json_encode([
                    'proxy_type' => 'SOCKS5',
                    'proxy_ip' => 'proxy.example',
                    'proxy_port' => 51080,
                    'proxy_hostname_lookup' => true,
                    'proxy_bittorrent' => true,
                    'proxy_peer_connections' => true,
                ], \JSON_THROW_ON_ERROR), [
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse('Ok.');
        });

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example', 51080);
        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));

        $subscriber = new TorrentProxySubscriber($synchronizer);
        $subscriber->onProxySettingsChanged(new ProxySettingsChangedEvent($settings));

        $this->assertSame([
            self::BASE_URL.'/api/v2/torrents/pause',
            self::BASE_URL.'/api/v2/app/setPreferences',
            self::BASE_URL.'/api/v2/app/preferences',
            self::BASE_URL.'/api/v2/torrents/resume',
        ], $calls);
        $this->assertSame('proxy.example', $sentPreferences['proxy_ip']);
        $this->assertSame(51080, $sentPreferences['proxy_port']);
    }
}
