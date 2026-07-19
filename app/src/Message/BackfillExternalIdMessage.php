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

namespace App\Message;

/**
 * Dispatched on the `async` transport for a one-off sweep of the whole local catalog
 * (issue #258) that resolves and caches a newly installed sync plugin's external id
 * (Anime::getExternalId(), issue #211) for every already-matching record, instead of
 * resolving it lazily one Anime at a time. Carries the raw plugin id string (validated into
 * a PluginId by the handler) rather than a PluginId value object, the same convention as
 * every other message here — it must stay trivially serializable for the queue transport.
 */
final readonly class BackfillExternalIdMessage
{
    public function __construct(
        public string $pluginId,
    ) {
    }
}
