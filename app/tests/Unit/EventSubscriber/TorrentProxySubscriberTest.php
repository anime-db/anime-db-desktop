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
     * real instance backed by a MockHttpClient stands in for it instead. The direct (HTTP
     * protocol) path is used here since it is a single setPreferences() call with no
     * pause/confirm/resume ordering to assert on — that fail-closed sequence is covered in depth
     * by TorrentProxySynchronizerTest itself.
     */
    public function testForwardsEventSettingsToSynchronizer(): void
    {
        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('Ok.');
        });

        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.example', 3128);
        $synchronizer = new TorrentProxySynchronizer(new QbittorrentClient($httpClient, self::BASE_URL));

        $subscriber = new TorrentProxySubscriber($synchronizer);
        $subscriber->onProxySettingsChanged(new ProxySettingsChangedEvent($settings));

        $this->assertSame([self::BASE_URL.'/api/v2/app/setPreferences'], $calls);
    }
}
