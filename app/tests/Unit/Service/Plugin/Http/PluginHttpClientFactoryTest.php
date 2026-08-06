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

namespace App\Tests\Unit\Service\Plugin\Http;

use App\Service\Plugin\Http\PluginHttpClientFactory;
use App\Service\ProxyConfigProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Symfony's `Psr18Client` — the class behind the `Psr\Http\Client\ClientInterface` binding
 * (issue #293 item 4) — also implements PSR-17's `RequestFactoryInterface`/`StreamFactoryInterface`.
 * Nothing in `config/services.yaml` relies on that (issue #309: plugins already get those two
 * interfaces from the global `Http\Discovery\Psr17Factory` binding in
 * `config/packages/http_discovery.yaml`), but this documents that the returned client could
 * build its own requests too, since a plugin might reasonably expect that from a PSR-18 client.
 */
final class PluginHttpClientFactoryTest extends TestCase
{
    private function createFactory(): PluginHttpClientFactory
    {
        return new PluginHttpClientFactory(new ProxyConfigProvider(sys_get_temp_dir().'/anime-plugin-http-client-factory-test-'.uniqid().'.json'));
    }

    public function testCreateReturnsClientThatIsAlsoBothPsr17Factories(): void
    {
        $client = $this->createFactory()->create();

        self::assertInstanceOf(ClientInterface::class, $client);
        self::assertInstanceOf(RequestFactoryInterface::class, $client);
        self::assertInstanceOf(StreamFactoryInterface::class, $client);
    }

    public function testCreatedFactoriesCanBuildARequestWithABodyStream(): void
    {
        $client = $this->createFactory()->create();
        self::assertInstanceOf(RequestFactoryInterface::class, $client);
        self::assertInstanceOf(StreamFactoryInterface::class, $client);

        $stream = $client->createStream('grant_type=refresh_token');
        $request = $client->createRequest('POST', 'https://example.test/oauth/token')
            ->withBody($stream);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.test/oauth/token', (string) $request->getUri());
        self::assertSame('grant_type=refresh_token', (string) $request->getBody());
    }
}
