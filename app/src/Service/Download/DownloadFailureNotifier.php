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

namespace App\Service\Download;

use App\Entity\Download;
use App\Service\Notification\Kind\DownloadFailedNotificationKind;
use App\Service\Notification\NotificationCenter;
use Psr\Log\LoggerInterface;

/**
 * Turns a `Download::markFailed()` transition into a `download_failed` notification (issue #1026).
 * The source is the status transition, not a particular service: every place that gets `true` from
 * `markFailed()` calls {@see self::notify()} after the flush that persisted the failure.
 *
 * Never throws: a notification must not change the outcome of the poll that raised it.
 */
class DownloadFailureNotifier
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(Download $download, ?string $reason): void
    {
        try {
            $anime = $download->getAnime();
            $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once it has a Download row pointing at it.');

            $this->center->record(DownloadFailedNotificationKind::draft($anime->getTitle(), $animeId, $reason));
        } catch (\Throwable $exception) {
            $this->logger->error('Preparing the download_failed notification failed; the download itself is not affected.', ['exception' => $exception]);
        }
    }
}
