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

namespace App\Message;

/**
 * Dispatched on the `media` transport for one plugin-supplied image URL at a time — one message
 * per URL, not per anime (issue #508): a single anime can carry a dozen gallery URLs, and
 * bundling them into one message would occupy the consumer for however long that whole batch
 * takes, which is exactly the delay the `media` transport's low priority (see messenger.yaml) is
 * meant to keep off the `async` transport's own messages.
 *
 * Carries the URL itself rather than an id the handler could re-resolve through the plugin: the
 * source `PluginAnimeData` this came from is already in memory at dispatch time
 * (BulkFillerService::build()), and re-querying the plugin later would be a redundant round trip
 * that could also return something different, since the external source can change between the
 * bulk scan and this message being processed.
 */
final readonly class DownloadAnimeMediaMessage
{
    public function __construct(
        public int $animeId,
        public string $url,
        public bool $isCover,
    ) {
    }
}
