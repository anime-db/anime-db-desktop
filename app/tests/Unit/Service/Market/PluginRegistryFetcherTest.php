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

namespace App\Tests\Unit\Service\Market;

use App\Service\Market\Exception\PluginRegistryFetchException;
use App\Service\Market\PluginRegistryFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginRegistryFetcherTest extends TestCase
{
    public function testFetchesRegistryAndSignatureFromTheFirstMirror(): void
    {
        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return str_ends_with($url, '.sig') ? new MockResponse('c2ln') : new MockResponse('{"sequence":1}');
        }, null);

        $document = (new PluginRegistryFetcher($httpClient))->fetch();

        $this->assertSame('{"sequence":1}', $document->registryJson);
        $this->assertSame('c2ln', $document->signatureBase64);
        $this->assertSame([
            'https://mr01.anime-db.org/plugins-registry.json',
            'https://mr01.anime-db.org/plugins-registry.json.sig',
        ], $requestedUrls);
    }

    public function testFallsBackToTheSecondMirrorWhenTheFirstIsUnreachable(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'mr01.anime-db.org')) {
                throw new TransportException('Connection refused.');
            }

            return str_ends_with($url, '.sig') ? new MockResponse('c2ln') : new MockResponse('{"sequence":1}');
        }, null);

        $document = (new PluginRegistryFetcher($httpClient))->fetch();

        $this->assertSame('{"sequence":1}', $document->registryJson);
        $this->assertSame('c2ln', $document->signatureBase64);
    }

    public function testThrowsWhenEveryMirrorFails(): void
    {
        $httpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null);

        $this->expectException(PluginRegistryFetchException::class);

        (new PluginRegistryFetcher($httpClient))->fetch();
    }
}
