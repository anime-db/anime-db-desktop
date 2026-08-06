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

namespace App\Entity\Enum;

/**
 * Coarse failure category for a proxy connectivity test (see ProxyTestService, issue #328).
 * Deliberately excludes anything the underlying transport exception's message might contain
 * (host, port, credentials, raw curl error text) — only a category ever reaches the response.
 */
enum ProxyTestOutcome: string
{
    case Timeout = 'timeout';
    case ConnectionRefused = 'connection_refused';
    case AuthFailed = 'auth_failed';
    case UnknownError = 'unknown_error';
}
