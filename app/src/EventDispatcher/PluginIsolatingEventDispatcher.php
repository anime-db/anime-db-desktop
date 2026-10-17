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

namespace App\EventDispatcher;

use AnimeDb\PluginContracts\Catalog\AnimeAddedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeRemovedEvent;
use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Isolates the listeners that plugins register for the contract events they are notified about
 * (issue #1075): an exception of one plugin listener is logged, the remaining listeners are still
 * called and dispatch() returns to its caller — no 500 after the data was already saved, no lost
 * {@see DownloadCompletedEvent}. Wrapping the dispatch() call in the caller would not do: the
 * listeners after the failing one would never run.
 *
 * Only a listener whose class lives in a plugin namespace (`AnimeDb\Plugins\`) is isolated; a core
 * listener keeps the usual behaviour. Any other event is dispatched by the decorated dispatcher
 * untouched.
 *
 * A listener that failed in a flush closes the shared EntityManager. Right after every plugin
 * listener a closed manager is reset through the registry, so the next listener and the caller
 * work with an open one.
 */
#[AsDecorator(decorates: 'event_dispatcher')]
final class PluginIsolatingEventDispatcher implements EventDispatcherInterface
{
    private const array ISOLATED = [
        AnimeAddedEvent::class => true,
        AnimeChangedEvent::class => true,
        AnimeRemovedEvent::class => true,
        AnimeFilesChangedEvent::class => true,
        DownloadCompletedEvent::class => true,
    ];

    private const string PLUGIN_NAMESPACE = 'AnimeDb\\Plugins\\';

    public function __construct(
        #[AutowireDecorated]
        private readonly EventDispatcherInterface $inner,
        private readonly ManagerRegistry $registry,
        #[Autowire(service: 'logger')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if (!isset(self::ISOLATED[$event::class])) {
            return $this->inner->dispatch($event, $eventName);
        }

        $name = $eventName ?? $event::class;
        foreach ($this->inner->getListeners($name) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            if (!$this->isPluginListener($listener)) {
                $listener($event, $name, $this);

                continue;
            }

            try {
                $listener($event, $name, $this);
            } catch (\Throwable $exception) {
                $this->logger->error('A plugin event subscriber failed.', [
                    'event' => $event::class,
                    'exception' => $exception,
                ]);
            }

            $this->resetClosedManagers();
        }

        return $event;
    }

    /** @param callable(): mixed $listener lazy listeners arrive as arrays, as for the core dispatcher */
    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    /** @param callable(): mixed $listener */
    public function removeListener(string $eventName, callable|array $listener): void
    {
        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }

    /** @param callable(): mixed $listener */
    public function getListenerPriority(string $eventName, callable|array $listener): ?int
    {
        return $this->inner->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->inner->hasListeners($eventName);
    }

    private function isPluginListener(mixed $listener): bool
    {
        // The profiler (debug) wraps every listener it traces.
        if ($listener instanceof WrappedListener) {
            $listener = $listener->getWrappedListener();
        }

        $owner = \is_array($listener) ? ($listener[0] ?? null) : $listener;
        $class = \is_object($owner) ? $owner::class : (\is_string($owner) ? $owner : null);

        return $class !== null && str_starts_with($class, self::PLUGIN_NAMESPACE);
    }

    private function resetClosedManagers(): void
    {
        foreach ($this->registry->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);
            }
        }
    }
}
