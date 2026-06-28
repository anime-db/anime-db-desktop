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

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

final class WsHandshake
{
    private const MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function validate(Request $request): ?string
    {
        $upgrade = strtolower($request->headers->get('Upgrade', ''));
        $key = $request->headers->get('Sec-Websocket-Key', '');

        if ($upgrade !== 'websocket' || $key === '') {
            return null;
        }

        return base64_encode(sha1($key.self::MAGIC, true));
    }
}
