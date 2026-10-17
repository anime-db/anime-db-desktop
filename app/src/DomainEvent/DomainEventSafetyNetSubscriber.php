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

namespace App\DomainEvent;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Safety net for events left in the "ready" buffer: a flush() made on an EntityManager that is
 * not the decorated one (e.g. `$args->getObjectManager()` inside a Doctrine listener) promotes
 * its events, but nothing hands them out until the next decorated flush. The end of a request, of
 * a console command and of a Messenger message are the points after which holding them back makes
 * no sense — dispatch what is ready, then reset the publisher so nothing outlives the unit of
 * work in the long-lived FrankenPHP worker / consumer.
 */
final class DomainEventSafetyNetSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DomainEventPublisher $publisher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'drain',
            ConsoleEvents::TERMINATE => 'drain',
            WorkerMessageHandledEvent::class => 'drain',
            WorkerMessageFailedEvent::class => 'drain',
        ];
    }

    public function drain(): void
    {
        $this->publisher->dispatchReady();
        $this->publisher->reset();
    }
}
