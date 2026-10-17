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

namespace App\EventSubscriber;

use App\Event\StorageConnected;
use App\Message\ScanStorageMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the regular {@see ScanStorageMessage} when a storage becomes available, at most once per
 * {@see self::MIN_INTERVAL_SECONDS} per storage (issue #1079). The last-queued marker lives in the
 * file-backed `cache.app` pool, keyed by storage id: `app:storage:connected` is a one-shot console
 * process, so process memory would forget the previous call and a flapping drive or share would
 * queue a full scan every time.
 */
final class StorageConnectedScanSubscriber implements EventSubscriberInterface
{
    public const int MIN_INTERVAL_SECONDS = 600;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorageConnected::class => 'onStorageConnected',
        ];
    }

    public function onStorageConnected(StorageConnected $event): void
    {
        $item = $this->cache->getItem(self::cacheKey($event->storageId));
        if ($item->isHit()) {
            return;
        }

        $this->messageBus->dispatch(new ScanStorageMessage($event->storageId));

        // Marked only after a successful dispatch: a failed queueing must not suppress the scan for the whole interval.
        $item->set(true)->expiresAfter(self::MIN_INTERVAL_SECONDS);
        $this->cache->save($item);
    }

    /** PSR-6 forbids `{}()/\@:` in keys, so the key is built from a prefix and the integer id only. */
    public static function cacheKey(int $storageId): string
    {
        return \sprintf('storage_connected_scan_%d', $storageId);
    }
}
