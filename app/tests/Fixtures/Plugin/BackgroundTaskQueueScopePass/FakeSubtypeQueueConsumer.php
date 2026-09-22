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

namespace AnimeDb\Plugins\FakeVendor;

/**
 * Fixture used by BackgroundTaskQueueScopePassTest to reproduce the "mismatched type with a
 * default" trap called out in issue #702: a constructor parameter typed to a *subtype* of
 * {@see \AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface} (not the interface
 * itself) is never recognized by the pass under test, so no binding is added for it — and because
 * the parameter also carries a `null` default, the container compiles without complaint rather
 * than failing loudly, leaving this property `null` at runtime instead of a scoped queue.
 */
final class FakeSubtypeQueueConsumer
{
    public function __construct(
        public readonly ?FakeQueueSubtype $queue = null,
    ) {
    }
}
