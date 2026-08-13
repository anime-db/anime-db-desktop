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

namespace App\Service\Exception;

/**
 * Thrown by {@see \App\Service\AppConfigStore::update()} when a bounded number of non-blocking
 * flock() acquire attempts all failed because another writer already holds the lock. Callers
 * fail fast instead of queueing indefinitely behind a stuck or slow concurrent write, the same
 * trade-off {@see \App\Service\Plugin\Exception\PluginsConfigStoreLockedException} makes for
 * plugins.json (issue #340).
 */
final class AppConfigStoreLockedException extends AppConfigStoreException
{
    public function __construct(string $path, int $attempts)
    {
        parent::__construct(\sprintf(
            'Could not acquire the lock on "%s" after %d attempt(s): another writer already holds it.',
            $path,
            $attempts,
        ));
    }
}
