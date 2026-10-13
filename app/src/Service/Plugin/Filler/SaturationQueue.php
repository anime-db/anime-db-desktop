<?php

declare(strict_types=1);

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

namespace App\Service\Plugin\Filler;

use App\Message\SaturateAnimeMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The one place that queues {@see SaturateAnimeMessage} (issue #1049): interactive triggers go to
 * `saturation`, mass paths (pull sync, v1 import, storage scan) to `saturation_bulk`.
 */
final class SaturationQueue
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function enqueue(int $animeId, bool $force = false, bool $bulk = false, int $delaySeconds = 0): void
    {
        $this->dispatch(new SaturateAnimeMessage($animeId, $force, $bulk ? SaturateAnimeMessage::TRANSPORT_BULK : SaturateAnimeMessage::TRANSPORT), $delaySeconds);
    }

    /** Re-queues $message into the transport it was queued into, optionally delayed. */
    public function dispatch(SaturateAnimeMessage $message, int $delaySeconds = 0): void
    {
        $stamps = [new TransportNamesStamp([$message->transport])];
        if ($delaySeconds > 0) {
            $stamps[] = new DelayStamp($delaySeconds * 1000);
        }

        $this->messageBus->dispatch($message, $stamps);
    }
}
