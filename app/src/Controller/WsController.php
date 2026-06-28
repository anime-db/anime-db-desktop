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

namespace App\Controller;

use App\Service\WsPublisher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class WsController
{
    public function __construct(private readonly WsPublisher $publisher)
    {
    }

    #[Route('/ws')]
    public function connect(Request $request): Response
    {
        $upgrade = strtolower($request->headers->get('Upgrade', ''));
        $key     = $request->headers->get('Sec-Websocket-Key', '');

        if ($upgrade !== 'websocket' || $key === '') {
            return new Response('WebSocket upgrade required', Response::HTTP_UPGRADE_REQUIRED, [
                'Upgrade' => 'websocket',
            ]);
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $publisher = $this->publisher;

        $response = new StreamedResponse(static function () use ($publisher): void {
            ignore_user_abort(false);
            set_time_limit(0);

            while (!connection_aborted()) {
                $event = $publisher->next();
                if ($event !== null) {
                    $json  = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $len   = strlen($json);
                    // WebSocket text frame (FIN=1, opcode=1), server-to-client (no mask)
                    $frame = "\x81";
                    if ($len <= 125) {
                        $frame .= chr($len);
                    } elseif ($len <= 0xFFFF) {
                        $frame .= "\x7e" . pack('n', $len);
                    } else {
                        $frame .= "\x7f" . pack('J', $len);
                    }
                    echo $frame . $json;
                    flush();
                } else {
                    usleep(50_000);
                }
            }
        });

        $response->setStatusCode(101);
        $response->headers->set('Upgrade', 'websocket');
        $response->headers->set('Connection', 'Upgrade');
        $response->headers->set('Sec-WebSocket-Accept', $accept);
        $response->headers->remove('Content-Type');

        return $response;
    }
}
