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
use App\Entity\Enum\ProxyTestOutcome;
use App\Entity\ValueObject\ProxySettings;
use App\Service\Http\ProxyTestService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProxyTestServiceTest extends TestCase
{
    private function manualSettings(): ProxySettings
    {
        return new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.example', 8080, 'user', 'secret');
    }

    public function testSuccessfulRequestReturnsElapsedTime(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertTrue($result->success);
        $this->assertNull($result->failureReason);
        $this->assertGreaterThanOrEqual(0, $result->elapsedMs);
    }

    public function testTimeoutIsClassifiedAsTimeout(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TimeoutException('Operation timed out after 10000 milliseconds');
        });
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertNull($result->elapsedMs);
        $this->assertSame(ProxyTestOutcome::Timeout, $result->failureReason);
    }

    public function testConnectionRefusedIsClassifiedAsConnectionRefused(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Failed to connect to proxy.example port 8080: Connection refused');
        });
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertSame(ProxyTestOutcome::ConnectionRefused, $result->failureReason);
    }

    public function testUnresolvableProxyIsClassifiedAsConnectionRefused(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Could not resolve proxy: proxy.example');
        });
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertSame(ProxyTestOutcome::ConnectionRefused, $result->failureReason);
    }

    public function testProxyAuthenticationErrorViaExceptionIsClassifiedAsAuthFailed(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Received HTTP code 407 from proxy after CONNECT');
        });
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertSame(ProxyTestOutcome::AuthFailed, $result->failureReason);
    }

    public function testProxyAuthenticationErrorViaStatusCodeIsClassifiedAsAuthFailed(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 407]));
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'http://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertSame(ProxyTestOutcome::AuthFailed, $result->failureReason);
    }

    public function testUnrecognizedTransportErrorIsClassifiedAsUnknownError(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('SSL certificate problem: unable to get local issuer certificate');
        });
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertSame(ProxyTestOutcome::UnknownError, $result->failureReason);
    }

    public function testFailureResultNeverExposesHostPortOrCredentials(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Failed to connect to proxy.example port 8080 with user "user": Connection refused');
        });
        $service = new ProxyTestService($client);

        $result = $service->test($this->manualSettings(), 'https://anime-db.org');

        $this->assertFalse($result->success);
        $this->assertContains($result->failureReason, ProxyTestOutcome::cases());
        // The result carries only an enum case, never the exception's message/host/port/creds.
        $this->assertNull($result->elapsedMs);
    }

    public function testNoProxyOptionIsSentWhenModeIsNone(): void
    {
        $none = new ProxySettings(ProxyMode::None, ProxyProtocol::Http, 'proxy.example', 8080);

        $capturedOptions = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });
        $service = new ProxyTestService($client);

        $service->test($none, 'https://anime-db.org');

        $this->assertIsArray($capturedOptions);
        $this->assertArrayNotHasKey('proxy', $capturedOptions);
    }
}
