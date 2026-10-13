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

namespace App\Tests\Support;

use App\Message\SaturateAnimeMessage;
use App\Service\Plugin\Filler\SaturationQueue;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * A {@see MessageBusInterface} that records what was dispatched, to put a real {@see SaturationQueue}
 * on top of it and assert on the queued saturation messages (issue #1049).
 */
final class RecordingSaturationBus implements MessageBusInterface
{
    /** @var list<array{0: object, 1: array<array-key, object>}> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = [$message, $stamps];

        return new Envelope($message, $stamps);
    }

    public function queue(): SaturationQueue
    {
        return new SaturationQueue($this);
    }

    /**
     * @return list<array{id: int, force: bool, transport: string}> one entry per queued saturation message
     */
    public function saturations(): array
    {
        $result = [];
        foreach ($this->dispatched as [$message, $stamps]) {
            if (!$message instanceof SaturateAnimeMessage) {
                continue;
            }
            $names = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof TransportNamesStamp));
            $result[] = ['id' => $message->animeId, 'force' => $message->force, 'transport' => $names[0]->getTransportNames()[0] ?? ''];
        }

        return $result;
    }
}
