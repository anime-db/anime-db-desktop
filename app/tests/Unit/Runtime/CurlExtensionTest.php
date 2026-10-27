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

namespace App\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The market and the proxy settings page depend on ext-curl (SOCKS5 is only supported by the curl
 * transport), so the packaged runtime must ship a curl build with TLS and SOCKS5 support. No
 * network requests are made: only the extension's capabilities are inspected.
 */
#[Group('runtime-parity')]
final class CurlExtensionTest extends TestCase
{
    public function testCurlExtensionIsLoaded(): void
    {
        self::assertTrue(\extension_loaded('curl'));
    }

    public function testCurlSupportsSsl(): void
    {
        $version = curl_version();

        self::assertIsArray($version);
        self::assertNotSame(0, $version['features'] & \CURL_VERSION_SSL);
    }

    public function testCurlAcceptsSocks5HostnameProxyType(): void
    {
        $handle = curl_init();

        self::assertTrue(curl_setopt($handle, \CURLOPT_PROXYTYPE, \CURLPROXY_SOCKS5_HOSTNAME));
    }
}
