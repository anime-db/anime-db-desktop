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

namespace App\Tests\Unit\Entity\ValueObject;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxySettingsTest extends TestCase
{
    public function testManualModeWithValidHostAndPortIsKept(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, '127.0.0.1', 1080);

        $this->assertSame(ProxyMode::Manual, $settings->mode);
    }

    public function testManualModeWithoutHostIsDowngradedToNone(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, null, 8080);

        $this->assertSame(ProxyMode::None, $settings->mode);
    }

    public function testManualModeWithEmptyHostIsDowngradedToNone(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '', 8080);

        $this->assertSame(ProxyMode::None, $settings->mode);
    }

    public function testManualModeWithoutPortIsDowngradedToNone(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', null);

        $this->assertSame(ProxyMode::None, $settings->mode);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPortProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above 65535' => [65536];
    }

    #[DataProvider('invalidPortProvider')]
    public function testManualModeWithOutOfRangePortIsDowngradedToNone(int $port): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', $port);

        $this->assertSame(ProxyMode::None, $settings->mode);
    }

    public function testNoneModeIsKeptRegardlessOfHostAndPort(): void
    {
        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Http, '127.0.0.1', 8080);

        $this->assertSame(ProxyMode::None, $settings->mode);
    }

    public function testToProxyUrlReturnsNullWhenModeIsNone(): void
    {
        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Socks5, '127.0.0.1', 1080);

        $this->assertNull($settings->toProxyUrl());
    }

    public function testToProxyUrlBuildsHttpUrlWithoutCredentials(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080);

        $this->assertSame('http://127.0.0.1:8080', $settings->toProxyUrl());
    }

    public function testToProxyUrlBuildsSocks5UrlWithCredentials(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.local', 1080, 'user', 'pass');

        $this->assertSame('socks5h://user:pass@proxy.local:1080', $settings->toProxyUrl());
    }

    public function testToProxyUrlUsesRemoteDnsSchemeForSocks5(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.local', 1080);

        $this->assertSame('socks5h://proxy.local:1080', $settings->toProxyUrl());
    }

    public function testToProxyUrlBuildsUrlWithUsernameOnly(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.local', 8080, 'user');

        $this->assertSame('http://user@proxy.local:8080', $settings->toProxyUrl());
    }

    public function testToProxyUrlOmitsCredentialsWhenUsernameIsEmptyString(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.local', 8080, '', 'pass');

        $this->assertSame('http://proxy.local:8080', $settings->toProxyUrl());
    }

    public function testToProxyUrlPercentEncodesCredentialsWithSpecialCharacters(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.local', 8080, 'us:er', 'p@ss');

        $this->assertSame('http://us%3Aer:p%40ss@proxy.local:8080', $settings->toProxyUrl());
    }

    public function testStringRepresentationOfNoneModeDoesNotLeakAnything(): void
    {
        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Http, '127.0.0.1', 8080, 'user', 'secret');

        $this->assertSame('ProxySettings(mode=none)', (string) $settings);
    }

    public function testStringRepresentationOfManualModeExcludesCredentials(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, '127.0.0.1', 1080, 'user', 'super-secret-password');

        $string = (string) $settings;

        $this->assertStringNotContainsString('user', $string);
        $this->assertStringNotContainsString('super-secret-password', $string);
        $this->assertSame('ProxySettings(mode=manual, protocol=socks5, host=127.0.0.1, port=1080)', $string);
    }

    public function testAllowsIncomingTorrentConnectionsIsFalseOnlyForSocks5(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, '127.0.0.1', 1080);

        $this->assertFalse($settings->allowsIncomingTorrentConnections());
    }

    public function testAllowsIncomingTorrentConnectionsIsTrueForNoProxy(): void
    {
        $settings = new ProxySettings(ProxyMode::None, ProxyProtocol::Socks5, null, null);

        $this->assertTrue($settings->allowsIncomingTorrentConnections());
    }

    public function testAllowsIncomingTorrentConnectionsIsTrueForHttpProxy(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, 'proxy.local', 8080);

        $this->assertTrue($settings->allowsIncomingTorrentConnections());
    }

    public function testAllowsIncomingTorrentConnectionsIsTrueWhenManualSocks5DowngradesToNone(): void
    {
        $settings = new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, null, null);

        $this->assertTrue($settings->allowsIncomingTorrentConnections());
    }
}
