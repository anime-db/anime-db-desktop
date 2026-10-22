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

namespace App\Controller;

use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Check that a request comes from the desktop shell's own window: it carries the shell header,
 * which a foreign page cannot send cross-site without a CORS preflight the backend never approves,
 * and its `Host` is a loopback name, which stops DNS rebinding (a foreign domain re-pointed at
 * 127.0.0.1 is same-origin with the backend and may set any header).
 *
 * The check must be the first thing an endpoint does: touching a UNC path (`\\host\share`) makes
 * the machine talk to that host, so any filesystem access before it would leak credentials.
 */
final class ShellRequestGuard
{
    public const string SHELL_HEADER = 'X-AnimeDB-Shell';
    public const string SHELL_HEADER_VALUE = '1';

    /** The backend listens on loopback only; any other Host means a foreign page reached it (DNS rebinding). */
    private const array ALLOWED_HOSTS = ['127.0.0.1', 'localhost'];

    public static function isShellRequest(Request $request): bool
    {
        if ($request->headers->get(self::SHELL_HEADER) !== self::SHELL_HEADER_VALUE) {
            return false;
        }

        try {
            return \in_array(strtolower($request->getHost()), self::ALLOWED_HOSTS, true);
        } catch (SuspiciousOperationException) {
            // Trusted hosts are configured and the Host is not among them.
            return false;
        }
    }
}
