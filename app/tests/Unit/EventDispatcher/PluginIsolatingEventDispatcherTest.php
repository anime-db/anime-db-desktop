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

namespace App\Tests\Unit\EventDispatcher {
    use AnimeDb\PluginContracts\Catalog\AnimeAddedEvent;
    use AnimeDb\PluginContracts\Catalog\AnimeChangedEvent;
    use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
    use AnimeDb\PluginContracts\Catalog\AnimeRemovedEvent;
    use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
    use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
    use AnimeDb\PluginContracts\Download\DownloadTaskId;
    use AnimeDb\PluginContracts\Model\AnimeId;
    use AnimeDb\Plugins\IsolationVendor\ClosingListener;
    use AnimeDb\Plugins\IsolationVendor\FailingListener;
    use AnimeDb\Plugins\IsolationVendor\ObservingListener;
    use AnimeDb\Plugins\IsolationVendor\RecordingListener;
    use App\EventDispatcher\PluginIsolatingEventDispatcher;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\Persistence\ManagerRegistry;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\EventDispatcher\Debug\WrappedListener;
    use Symfony\Component\EventDispatcher\EventDispatcher;
    use Symfony\Component\Stopwatch\Stopwatch;

    final class PluginIsolatingEventDispatcherTest extends TestCase
    {
        /** @return iterable<string, array{object}> */
        public static function isolatedEvents(): iterable
        {
            $anime = new AnimeId(1);

            yield 'added' => [new AnimeAddedEvent($anime)];
            yield 'changed' => [new AnimeChangedEvent($anime, [])];
            yield 'removed' => [new AnimeRemovedEvent($anime, [])];
            yield 'files changed (created)' => [new AnimeFilesChangedEvent($anime, FilesChangeReason::Created)];
            yield 'files changed (path changed)' => [new AnimeFilesChangedEvent($anime, FilesChangeReason::PathChanged)];
            yield 'files changed (download finished)' => [new AnimeFilesChangedEvent($anime, FilesChangeReason::DownloadFinished)];
            yield 'files changed (unlinked)' => [new AnimeFilesChangedEvent($anime, FilesChangeReason::Unlinked)];
            yield 'download completed' => [new DownloadCompletedEvent($anime, new DownloadTaskId('abc'))];
        }

        #[DataProvider('isolatedEvents')]
        public function testFailingPluginListenerDoesNotStopTheNextOneNorTheCaller(object $event): void
        {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error');
            $next = new RecordingListener();
            $dispatcher = $this->dispatcher($event::class, [new FailingListener(), $next], logger: $logger);

            $returned = $dispatcher->dispatch($event);

            self::assertSame($event, $returned);
            self::assertSame([$event], $next->received);
        }

        public function testPluginListenerWrappedByTheProfilerIsStillIsolated(): void
        {
            $inner = new EventDispatcher();
            $next = new RecordingListener();
            $inner->addListener(AnimeAddedEvent::class, new WrappedListener([new FailingListener(), '__invoke'], null, new Stopwatch()));
            $inner->addListener(AnimeAddedEvent::class, [$next, '__invoke']);
            $event = new AnimeAddedEvent(new AnimeId(1));

            (new PluginIsolatingEventDispatcher($inner, $this->createStub(ManagerRegistry::class), $this->createStub(LoggerInterface::class)))->dispatch($event);

            self::assertSame([$event], $next->received);
        }

        public function testClosedEntityManagerIsResetBeforeTheNextListener(): void
        {
            $state = new \stdClass();
            $state->open = true;
            $manager = $this->createStub(EntityManagerInterface::class);
            $manager->method('isOpen')->willReturnCallback(static fn (): bool => $state->open);
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManagers')->willReturn(['default' => $manager]);
            $registry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use ($state, $manager): EntityManagerInterface {
                $state->open = true;

                return $manager;
            });
            $observer = new ObservingListener($state);

            $this->dispatcher(AnimeAddedEvent::class, [new ClosingListener($state), $observer], $registry)->dispatch(new AnimeAddedEvent(new AnimeId(1)));

            self::assertSame([true], $observer->openWhenCalled);
            self::assertTrue($state->open);
        }

        public function testListenerThatIsNotFromAPluginKeepsPropagatingItsException(): void
        {
            $dispatcher = $this->dispatcher(AnimeAddedEvent::class, [new CoreFailingListener()]);

            $this->expectException(\RuntimeException::class);

            $dispatcher->dispatch(new AnimeAddedEvent(new AnimeId(1)));
        }

        /** @param list<object> $listeners */
        private function dispatcher(string $eventClass, array $listeners, ?ManagerRegistry $registry = null, ?LoggerInterface $logger = null): PluginIsolatingEventDispatcher
        {
            $inner = new EventDispatcher();
            foreach ($listeners as $listener) {
                $inner->addListener($eventClass, [$listener, '__invoke']);
            }

            $registry ??= $this->createStub(ManagerRegistry::class);

            return new PluginIsolatingEventDispatcher($inner, $registry, $logger ?? $this->createStub(LoggerInterface::class));
        }
    }

    final class CoreFailingListener
    {
        public function __invoke(object $event): void
        {
            throw new \RuntimeException('core listener failure');
        }
    }
}

namespace AnimeDb\Plugins\IsolationVendor {
    final class FailingListener
    {
        public function __invoke(object $event): void
        {
            throw new \RuntimeException('plugin listener failure');
        }
    }

    /** Closes the shared EntityManager and fails, as a listener whose flush failed does. */
    final class ClosingListener
    {
        public function __construct(private readonly \stdClass $state)
        {
        }

        public function __invoke(object $event): void
        {
            $this->state->open = false;

            throw new \RuntimeException('flush failed');
        }
    }

    final class ObservingListener
    {
        /** @var list<bool> */
        public array $openWhenCalled = [];

        public function __construct(private readonly \stdClass $state)
        {
        }

        public function __invoke(object $event): void
        {
            $this->openWhenCalled[] = $this->state->open;
        }
    }

    final class RecordingListener
    {
        /** @var list<object> */
        public array $received = [];

        public function __invoke(object $event): void
        {
            $this->received[] = $event;
        }
    }
}
