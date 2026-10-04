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
 * Dispatched on the `async` transport when an Anime's watchStatus changes (issue #214) — carries
 * only the id and the one sync plugin to push it to, the handler loads the current entity state
 * itself before pushing it to that plugin. One message per active plugin (issue #868):
 * WatchProgressPushSubscriber dispatches a separate PushSyncMessage for each plugin
 * SyncRegistry::allActive() returns, so a push failure in one plugin's message only affects that
 * plugin's own retries, never the others.
 *
 * $pluginId is nullable for backward compatibility with messages already sitting in the queue
 * (data/queue.db) from before this property existed: a message serialized by the old
 * constructor-less-of-$pluginId shape deserializes with $pluginId left uninitialized, which
 * `$message->pluginId ?? null` reads as null without throwing — see PushSyncMessageHandler for
 * how that case is still handled (pushed to every active plugin, same as before this issue).
 *
 * $dispatchedAt anchors the push-on-edit TTL (issue #366): a message the handler picks up more
 * than app.sync.push_on_edit_ttl_seconds after this timestamp is dropped rather than pushed, on
 * the theory that an optimistic blind write's confidence that the source has not itself diverged
 * meanwhile decays with time — see .claude-docs/sync.md's "Ритм: push-on-edit". Stamped at
 * dispatch time (WatchProgressPushSubscriber), not derived from the message's own queued-at
 * metadata, so the TTL is measured from when the edit actually happened, not from an unrelated
 * transport implementation detail. This applies equally regardless of $pluginId, including the
 * backward-compatible old-format case.
 */
final readonly class PushSyncMessage
{
    public function __construct(
        public int $animeId,
        public \DateTimeImmutable $dispatchedAt,
        public ?string $pluginId = null,
    ) {
    }
}
