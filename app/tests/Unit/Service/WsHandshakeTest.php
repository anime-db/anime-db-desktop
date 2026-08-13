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

namespace App\Tests\Unit\Service;

use App\Service\WsHandshake;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WsHandshakeTest extends TestCase
{
    private WsHandshake $handshake;

    protected function setUp(): void
    {
        $this->handshake = new WsHandshake();
    }

    public function testReturnsNullWhenNoUpgradeHeader(): void
    {
        $this->assertNull($this->handshake->validate(new Request()));
    }

    public function testReturnsNullWhenUpgradeIsNotWebSocket(): void
    {
        $request = new Request();
        $request->headers->set('Upgrade', 'http2');
        $request->headers->set('Sec-Websocket-Key', 'dGhlIHNhbXBsZSBub25jZQ==');

        $this->assertNull($this->handshake->validate($request));
    }

    public function testReturnsNullWhenKeyIsMissing(): void
    {
        $request = new Request();
        $request->headers->set('Upgrade', 'websocket');

        $this->assertNull($this->handshake->validate($request));
    }

    public function testReturnsAcceptKeyForValidRequest(): void
    {
        $request = new Request();
        $request->headers->set('Upgrade', 'websocket');
        $request->headers->set('Sec-Websocket-Key', 'dGhlIHNhbXBsZSBub25jZQ==');

        // RFC 6455 §1.3 example: key + magic → "s3pPLMBiTxaQ9kYGzzhZRbK+xOo="
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $this->handshake->validate($request));
    }

    public function testAcceptsWebSocketHeaderCaseInsensitively(): void
    {
        $request = new Request();
        $request->headers->set('Upgrade', 'WebSocket');
        $request->headers->set('Sec-Websocket-Key', 'dGhlIHNhbXBsZSBub25jZQ==');

        $this->assertNotNull($this->handshake->validate($request));
    }
}
