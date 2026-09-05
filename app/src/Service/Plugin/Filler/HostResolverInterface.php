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

namespace App\Service\Plugin\Filler;

/**
 * Isolated behind an interface (rather than {@see HttpPluginMediaDownloader} calling the native
 * function directly) so a test can control exactly what a hostname resolves to on each call —
 * in particular, returning a different address on a second call for the same host, which is
 * what a DNS-rebinding attack looks like from the caller's side.
 */
interface HostResolverInterface
{
    /**
     * @return list<string> every address the host currently resolves to, or an empty list if it doesn't resolve
     */
    public function resolve(string $host): array;
}
