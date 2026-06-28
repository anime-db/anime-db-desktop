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

use App\Service\WsFrameEncoder;
use App\Service\WsHandshake;
use App\Service\WsPublisher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class WsController
{
    public function __construct(
        private readonly WsPublisher $publisher,
        private readonly WsHandshake $handshake,
        private readonly WsFrameEncoder $encoder,
    ) {
    }

    #[Route('/ws')]
    public function connect(Request $request): Response
    {
        $acceptKey = $this->handshake->validate($request);

        if ($acceptKey === null) {
            return new Response('WebSocket upgrade required', Response::HTTP_UPGRADE_REQUIRED, [
                'Upgrade' => 'websocket',
            ]);
        }

        $publisher = $this->publisher;
        $encoder = $this->encoder;

        $response = new StreamedResponse(static function () use ($publisher, $encoder): void {
            ignore_user_abort(false);
            set_time_limit(0);

            while (!connection_aborted()) {
                $event = $publisher->next();
                if ($event !== null) {
                    $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    echo $encoder->encode($json);
                    flush();
                } else {
                    usleep(50_000);
                }
            }
        });

        $response->setStatusCode(101);
        $response->headers->set('Upgrade', 'websocket');
        $response->headers->set('Connection', 'Upgrade');
        $response->headers->set('Sec-WebSocket-Accept', $acceptKey);
        $response->headers->remove('Content-Type');

        return $response;
    }
}
