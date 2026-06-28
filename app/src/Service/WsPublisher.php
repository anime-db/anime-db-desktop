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

/**
 * Queues backend events for delivery to connected WebSocket clients.
 *
 * Uses APCu shared memory for inter-worker IPC (statically compiled into
 * FrankenPHP). The WsController worker polls next() in its streaming loop;
 * other workers call publish() to enqueue events.
 */
class WsPublisher
{
    private const QUEUE_KEY = 'ws_events';

    /**
     * Enqueues an event for all connected WebSocket clients.
     */
    public function publish(string $event, mixed $data): void
    {
        if (!function_exists('apcu_fetch')) {
            return;
        }

        $success = false;
        /** @var list<array{event: string, data: mixed}> $queue */
        $queue = apcu_fetch(self::QUEUE_KEY, $success);
        $queue = $success ? $queue : [];
        $queue[] = ['event' => $event, 'data' => $data];
        apcu_store(self::QUEUE_KEY, $queue);
    }

    /**
     * Returns and removes the next queued event, or null if the queue is empty.
     *
     * @return array{event: string, data: mixed}|null
     */
    public function next(): ?array
    {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $success = false;
        /** @var list<array{event: string, data: mixed}> $queue */
        $queue = apcu_fetch(self::QUEUE_KEY, $success);
        if (!$success || empty($queue)) {
            return null;
        }

        $event = array_shift($queue);
        apcu_store(self::QUEUE_KEY, $queue);

        return $event;
    }
}
