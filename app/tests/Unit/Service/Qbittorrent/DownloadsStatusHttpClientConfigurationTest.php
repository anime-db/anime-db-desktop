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

use App\Controller\DownloadsStatusController;
use App\Service\Qbittorrent\QbittorrentClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Acceptance (issue #854): the downloads-status endpoint's qBittorrent requests must time out at
 * 2s, much tighter than the 5s/15s app.qbittorrent.http_client the download-completion poller
 * uses (app/config/services.yaml) — a stuck sidecar must not hold a FrankenPHP worker thread for
 * the longer window on every 2-second poll tick from the browser.
 *
 * Reflects all the way from the booted {@see DownloadsStatusController} through its injected
 * client down to the HTTP client's own default options, rather than asserting on the
 * app.qbittorrent.status_http_client service in isolation: that service existing with the right
 * timeout proves nothing about what the controller actually received — a typo'd or removed
 * `$client: '@app.qbittorrent.status_client'` binding in services.yaml would make autowiring
 * silently fall back to the shared 5s/15s App\Service\Qbittorrent\QbittorrentClient and this test
 * would stay green, since it is checking the wrong object. Also asserts on wall-clock time against
 * a black-hole address would be both slower and flaky under CI load, hence the reflection instead.
 */
final class DownloadsStatusHttpClientConfigurationTest extends KernelTestCase
{
    public function testControllerReceivesAnHttpClientThatTimesOutAtTwoSeconds(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $controller = $container->get(DownloadsStatusController::class);
        $client = (new \ReflectionProperty($controller, 'client'))->getValue($controller);

        self::assertNotSame(
            $container->get(QbittorrentClient::class),
            $client,
            'DownloadsStatusController must not end up wired to the shared 5s/15s qBittorrent client.',
        );

        /** @var HttpClientInterface $httpClient */
        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        $defaultOptions = (new \ReflectionProperty($httpClient, 'defaultOptions'))->getValue($httpClient);

        self::assertSame(2.0, $defaultOptions['timeout']);
        self::assertSame(2.0, $defaultOptions['max_duration']);
    }
}
