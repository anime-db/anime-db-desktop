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

namespace App\Tests\Unit\Service\Http;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use App\Service\Http\ProxyAwareHttpClient;
use App\Service\ProxyConfigProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProxyAwareHttpClientTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-proxy-aware-http-client-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testDoesNotAddProxyOptionsWhenModeIsNone(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $capturedOptions = null;

        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse();
        });

        (new ProxyAwareHttpClient($inner, $provider))->request('GET', 'https://example.test/');

        self::assertIsArray($capturedOptions);
        self::assertArrayNotHasKey('proxy', $capturedOptions);
        self::assertArrayNotHasKey('no_proxy', $capturedOptions);
    }

    public function testAddsProxyOptionsWhenModeIsManual(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080));
        $capturedOptions = null;

        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse();
        });

        (new ProxyAwareHttpClient($inner, $provider))->request('GET', 'https://example.test/');

        self::assertIsArray($capturedOptions);
        self::assertSame('http://127.0.0.1:8080', $capturedOptions['proxy']);
        self::assertSame('localhost,127.0.0.1,::1', $capturedOptions['no_proxy']);
    }

    public function testAddsSocks5ProxyOptionWhenProtocolIsSocks5(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, '127.0.0.1', 1080));
        $capturedOptions = null;

        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse();
        });

        (new ProxyAwareHttpClient($inner, $provider))->request('GET', 'https://example.test/');

        self::assertIsArray($capturedOptions);
        self::assertSame('socks5h://127.0.0.1:1080', $capturedOptions['proxy']);
    }

    public function testDoesNotOverrideCallerProvidedProxyOption(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080));
        $capturedOptions = null;

        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse();
        });

        (new ProxyAwareHttpClient($inner, $provider))->request('GET', 'https://example.test/', [
            'proxy' => 'http://caller-proxy.test:9090',
        ]);

        self::assertIsArray($capturedOptions);
        self::assertSame('http://caller-proxy.test:9090', $capturedOptions['proxy']);
    }

    public function testNoProxyAlwaysCoversLoopbackWhenManual(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.example.test', 1080));
        $capturedOptions = null;

        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse();
        });

        (new ProxyAwareHttpClient($inner, $provider))->request('GET', 'https://example.test/');

        self::assertIsArray($capturedOptions);
        self::assertStringContainsString('localhost', $capturedOptions['no_proxy']);
        self::assertStringContainsString('127.0.0.1', $capturedOptions['no_proxy']);
        self::assertStringContainsString('::1', $capturedOptions['no_proxy']);
    }

    public function testCreateForcesCurlHttpClientTransport(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);

        $client = ProxyAwareHttpClient::create($provider);

        $innerClient = (new \ReflectionProperty(ProxyAwareHttpClient::class, 'client'))->getValue($client);
        self::assertInstanceOf(CurlHttpClient::class, $innerClient);
    }

    public function testWithOptionsReturnsNewInstanceWrappingInnerWithOptions(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $capturedOptions = null;

        $inner = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse();
        });

        $client = new ProxyAwareHttpClient($inner, $provider);
        $withOptions = $client->withOptions(['timeout' => 42]);

        self::assertNotSame($client, $withOptions);
        self::assertInstanceOf(ProxyAwareHttpClient::class, $withOptions);

        $withOptions->request('GET', 'https://example.test/');

        self::assertIsArray($capturedOptions);
        self::assertSame(42.0, $capturedOptions['timeout']);
    }
}
