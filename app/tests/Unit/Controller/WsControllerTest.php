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

namespace App\Tests\Unit\Controller;

use App\Controller\WsController;
use App\Service\WsFrameEncoder;
use App\Service\WsHandshake;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WsControllerTest extends TestCase
{
    public function testReturnsUpgradeRequiredWhenHandshakeFails(): void
    {
        $publisher = $this->createMock(WsPublisher::class);
        $handshake = $this->createMock(WsHandshake::class);
        $encoder = $this->createMock(WsFrameEncoder::class);
        $handshake->method('validate')->willReturn(null);

        $controller = new WsController($publisher, $handshake, $encoder);
        $response = $controller->connect(new Request());

        $this->assertSame(Response::HTTP_UPGRADE_REQUIRED, $response->getStatusCode());
    }

    public function testUpgradeRequiredResponseIncludesWebSocketHeader(): void
    {
        $publisher = $this->createMock(WsPublisher::class);
        $handshake = $this->createMock(WsHandshake::class);
        $encoder = $this->createMock(WsFrameEncoder::class);
        $handshake->method('validate')->willReturn(null);

        $controller = new WsController($publisher, $handshake, $encoder);
        $response = $controller->connect(new Request());

        $this->assertSame('websocket', $response->headers->get('Upgrade'));
    }

    public function testReturnsSwitchingProtocolsForValidHandshake(): void
    {
        $publisher = $this->createMock(WsPublisher::class);
        $handshake = $this->createMock(WsHandshake::class);
        $encoder = $this->createMock(WsFrameEncoder::class);
        $handshake->method('validate')->willReturn('test-accept-key');

        $controller = new WsController($publisher, $handshake, $encoder);
        $response = $controller->connect(new Request());

        $this->assertSame(101, $response->getStatusCode());
    }

    public function testSwitchingProtocolsResponseHasCorrectHeaders(): void
    {
        $publisher = $this->createMock(WsPublisher::class);
        $handshake = $this->createMock(WsHandshake::class);
        $encoder = $this->createMock(WsFrameEncoder::class);
        $handshake->method('validate')->willReturn('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=');

        $controller = new WsController($publisher, $handshake, $encoder);
        $response = $controller->connect(new Request());

        $this->assertSame('websocket', $response->headers->get('Upgrade'));
        $this->assertSame('Upgrade', $response->headers->get('Connection'));
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response->headers->get('Sec-WebSocket-Accept'));
    }

    public function testHandshakeIsCalledWithRequest(): void
    {
        $publisher = $this->createMock(WsPublisher::class);
        $handshake = $this->createMock(WsHandshake::class);
        $encoder = $this->createMock(WsFrameEncoder::class);
        $request = new Request();

        $handshake->expects($this->once())
            ->method('validate')
            ->with($request)
            ->willReturn(null);

        $controller = new WsController($publisher, $handshake, $encoder);
        $controller->connect($request);
    }
}
