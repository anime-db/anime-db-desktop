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

use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use App\Entity\Anime;
use App\Service\Notification\Kind\DownloadCompletedKind;
use App\Service\Notification\NotificationCenter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The host's own subscriber to the existing {@see DownloadCompletedEvent}: records a
 * `download_completed` notification (issue #1026). The event is dispatched after the flush that
 * completed the download, outside any transaction. The poller does not guard the dispatch, so this
 * subscriber never throws: any failure is logged.
 */
final class DownloadCompletedNotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [DownloadCompletedEvent::class => 'onDownloadCompleted'];
    }

    public function onDownloadCompleted(DownloadCompletedEvent $event): void
    {
        try {
            $anime = $this->entityManager->find(Anime::class, $event->anime->value);
            if ($anime === null) {
                return;
            }

            $this->center->record(DownloadCompletedKind::downloadCompleted($anime->getTitle(), $event->anime->value));
        } catch (\Throwable $exception) {
            $this->logger->error('Preparing the download_completed notification failed; the download itself is not affected.', ['exception' => $exception]);
        }
    }
}
