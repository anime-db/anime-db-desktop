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

use App\DomainEvent\Catalog\AnimeEventCoalescer;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands the core's domain events to their subscribers strictly after the change that raised them
 * is final: after flush() has returned, or — inside an outer transaction — after that transaction
 * has committed. Never from Doctrine's own hooks, which fire inside an open transaction that may
 * still roll back.
 *
 * Two buffers:
 *  - "current flush": filled by {@see collect()} from onFlush and OVERWRITTEN each time, so the
 *    events of a flush that failed (it never reaches postFlush) cannot ride along with the next
 *    successful one;
 *  - "ready": filled by {@see promote()} from postFlush, which also builds the events — the
 *    factories may read an id that only exists after the INSERT. It is drained by
 *    {@see dispatchReady()}, called by {@see \App\Doctrine\DomainEventEntityManager} once flush()
 *    returned outside a transaction, or by {@see \App\Doctrine\DomainEventConnection} once the
 *    outermost commit() succeeded. A rollback to level 0 or a failed commit calls {@see discard()}.
 *
 * Dispatching is deliberately NOT done from postFlush: Doctrine dispatches it before its
 * postCommitCleanup(), and a subscriber that flushes in response would re-run the pending
 * collection deletions of the flush that is still in progress.
 *
 * The publisher never flushes: a subscriber that needs to write does its own flush(), which is a
 * new transaction whose events follow the same rules. A subscriber that flushes or commits while
 * being notified re-enters {@see dispatchReady()}; the nested call is a no-op and the outer loop
 * picks the newly ready events up, so nothing is delivered twice or lost. A subscriber exception
 * is logged and swallowed — it must not escape commit(), where DBAL would answer it with a
 * rollBack() on an already closed transaction and mask the real cause.
 */
final class DomainEventPublisher implements ResetInterface
{
    /** @var list<\Closure(): object> */
    private array $current = [];
    /** @var list<object> */
    private array $ready = [];
    /**
     * Events promoted inside an open transaction, one list per nesting level (index 0 is level 1):
     * a savepoint that rolls back must take only its own events with it.
     *
     * @var list<list<object>>
     */
    private array $levels = [];
    private bool $dispatching = false;
    private readonly AnimeEventCoalescer $coalescer;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        #[Autowire(service: 'logger')]
        private readonly LoggerInterface $logger,
    ) {
        $this->coalescer = new AnimeEventCoalescer();
    }

    /** @param list<\Closure(): object> $eventFactories */
    public function collect(array $eventFactories): void
    {
        $this->current = $eventFactories;
    }

    public function promote(): void
    {
        $factories = $this->current;
        $this->current = [];

        $events = [];
        foreach ($factories as $factory) {
            $events[] = $factory();
        }

        $top = array_key_last($this->levels);
        if ($top === null) {
            $this->ready = [...$this->ready, ...$events];
        } else {
            $this->levels[$top] = [...$this->levels[$top], ...$events];
        }
    }

    /**
     * Takes an event whose change is already final in the database (a write that bypassed the
     * UnitOfWork): outside a transaction it is delivered at once, inside one it waits for the
     * outermost commit and is dropped with its level on a rollback.
     */
    public function record(object $event): void
    {
        $top = array_key_last($this->levels);
        if ($top === null) {
            $this->ready[] = $event;
            $this->dispatchReady();
        } else {
            $this->levels[$top][] = $event;
        }
    }

    public function hasReady(): bool
    {
        return $this->ready !== [] || array_filter($this->levels) !== [];
    }

    /** A (nested) transaction began: the events promoted from now on belong to its level. */
    public function enterLevel(): void
    {
        $this->levels[] = [];
    }

    /** The innermost level committed: its events now belong to the parent level (or are ready at level 0). */
    public function commitLevel(): void
    {
        $events = array_pop($this->levels);
        if ($events === null || $events === []) {
            return;
        }

        $top = array_key_last($this->levels);
        if ($top === null) {
            $this->ready = [...$this->ready, ...$events];
        } else {
            $this->levels[$top] = [...$this->levels[$top], ...$events];
        }
    }

    /** The innermost level rolled back (or failed to commit): drop only its events. */
    public function rollBackLevel(): void
    {
        array_pop($this->levels);
        $this->current = [];
    }

    public function discard(): void
    {
        $this->current = [];
        $this->ready = [];
        $this->levels = [];
    }

    public function dispatchReady(): void
    {
        if ($this->dispatching) {
            return;
        }

        $this->dispatching = true;
        try {
            while ($this->ready !== []) {
                $batch = $this->ready;
                $this->ready = [];

                // The events of one record within one delivery are merged (see AnimeEventCoalescer).
                foreach ($this->coalescer->coalesce($batch) as $event) {
                    $this->notify($event);
                }
            }
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * Calls the subscribers one by one: the dispatcher itself would abandon the remaining ones
     * after the first exception.
     */
    private function notify(object $event): void
    {
        foreach ($this->eventDispatcher->getListeners($event::class) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                return;
            }

            try {
                $listener($event, $event::class, $this->eventDispatcher);
            } catch (\Throwable $exception) {
                $this->logger->error('A domain event subscriber failed.', [
                    'event' => $event::class,
                    'exception' => $exception,
                ]);
            }
        }
    }

    public function reset(): void
    {
        $this->discard();
        $this->dispatching = false;
    }
}
