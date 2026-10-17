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

namespace App\Tests\Unit\DomainEvent;

use App\Doctrine\DomainEventConnection;
use App\Doctrine\DomainEventEntityManager;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\DomainEvent\DomainEventPublisher;
use App\DomainEvent\DomainEventSafetyNetSubscriber;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\Event\WatchProgressChangedManuallyEvent;
use App\EventListener\DomainEventListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Domain events end to end over a real in-memory SQLite: the Doctrine listener, the publisher, the
 * decorated EntityManager and the event-aware connection wired the way the container wires them.
 */
final class DomainEventFlowTest extends TestCase
{
    private DomainEventConnection $connection;
    private EntityManager $rawEntityManager;
    private DomainEventEntityManager $entityManager;
    private DomainEventPublisher $publisher;
    private EventDispatcher $dispatcher;
    /** @var list<string> */
    private array $log = [];
    /** @var object{messages: list<string>} */
    private object $logger;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => DomainEventConnection::class,
        ], $config);
        $this->connection = $connection;
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->logger = $logger;

        $this->dispatcher = new EventDispatcher();
        $this->publisher = new DomainEventPublisher($this->dispatcher, $logger);
        $this->connection->setDomainEventPublisher($this->publisher);

        $this->rawEntityManager = new EntityManager($this->connection, $config);
        $this->rawEntityManager->getEventManager()->addEventListener(
            [Events::onFlush, Events::postFlush],
            new DomainEventListener($this->publisher),
        );
        $this->entityManager = new DomainEventEntityManager($this->rawEntityManager, $this->publisher);

        (new SchemaTool($this->rawEntityManager))->createSchema($this->rawEntityManager->getMetadataFactory()->getAllMetadata());

        $this->dispatcher->addListener(WatchProgressChangedManuallyEvent::class, function (WatchProgressChangedManuallyEvent $event): void {
            $this->log[] = 'event(tx='.($this->connection->isTransactionActive() ? 'open' : 'none').')';
        });
    }

    public function testEventIsDeliveredAfterFlushReturnedNotInPostFlush(): void
    {
        $this->rawEntityManager->getEventManager()->addEventListener(Events::postFlush, new class(function (): void {
            $this->log[] = 'postFlush';
        }) {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function postFlush(): void
            {
                ($this->callback)();
            }
        });

        $this->entityManager->persist($this->plannedAnime('A'));
        $this->entityManager->flush();
        $this->log[] = 'flush returned';

        self::assertSame(['postFlush', 'event(tx=none)', 'flush returned'], $this->log);
    }

    public function testEventInsideTransactionIsDeliveredOnlyAfterTheOuterCommit(): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->persist($this->plannedAnime('A'));
            $this->entityManager->flush();
            $this->log[] = 'after inner flush';
        });
        $this->log[] = 'after wrapInTransaction';

        self::assertSame(['after inner flush', 'event(tx=none)', 'after wrapInTransaction'], $this->log);
    }

    public function testEventIsNotDeliveredWhenTheOuterTransactionRollsBack(): void
    {
        try {
            $this->entityManager->wrapInTransaction(function (): void {
                $this->entityManager->persist($this->plannedAnime('A'));
                $this->entityManager->flush();

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // Expected: the rollback must leave nothing behind.
        }

        self::assertSame([], $this->log);
        self::assertFalse($this->publisher->hasReady());

        // ... and nothing leaks into the next, unrelated commit.
        $this->connection->beginTransaction();
        $this->connection->commit();
        self::assertSame([], $this->log);
    }

    public function testEventsOfARolledBackSavepointAreDroppedButTheOuterOnesSurvive(): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->persist($this->plannedAnime('Outer'));
            $this->entityManager->flush();

            // A bare savepoint: wrapInTransaction() would also close the entity manager.
            $this->connection->beginTransaction();
            $this->entityManager->persist($this->plannedAnime('Inner'));
            $this->entityManager->flush();
            $this->connection->rollBack();
        });

        // Exactly one event: the outer flush's; the savepoint's one is gone.
        self::assertSame(['event(tx=none)'], $this->log);
    }

    public function testSubscriberFlushingAfterRemovedCollectionItemsDoesNotLoseManyToManyRows(): void
    {
        $studios = [$this->studio('S1'), $this->studio('S2')];
        $labels = [new Label('L1'), new Label('L2')];
        $anime = $this->plannedAnime('A');
        foreach ($studios as $studio) {
            $this->entityManager->persist($studio);
            $anime->addStudio($studio);
        }
        foreach ($labels as $label) {
            $this->entityManager->persist($label);
            $anime->addLabel($label);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->log = [];

        $this->dispatcher->addListener(WatchProgressChangedManuallyEvent::class, function () use ($anime): void {
            $anime->setTitle('Renamed by subscriber');
            $this->entityManager->flush();
        });

        $anime->removeStudio($studios[0]);
        $anime->removeLabel($labels[0]);
        $anime->changeWatchStatusManually(WatchStatus::Watching);
        $this->entityManager->flush();

        self::assertSame(['event(tx=none)'], $this->log);
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_studios'));
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_labels'));
        self::assertSame('Renamed by subscriber', $this->connection->fetchOne('SELECT title FROM anime'));
    }

    public function testEventsOfAFailedFlushAreNeverDelivered(): void
    {
        $failing = new class {
            public bool $fail = true;

            public function onFlush(): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('flush failed');
                }
            }
        };
        $this->rawEntityManager->getEventManager()->addEventListener(Events::onFlush, $failing);

        $this->entityManager->persist($this->plannedAnime('Failed'));
        try {
            $this->entityManager->flush();
            self::fail('The flush must fail.');
        } catch (\RuntimeException) {
        }
        self::assertSame([], $this->log);

        $this->rawEntityManager->clear();
        $failing->fail = false;
        $this->entityManager->persist($this->unchangedAnime('Next'));
        $this->entityManager->flush();

        self::assertSame([], $this->log);
        self::assertFalse($this->publisher->hasReady());
    }

    public function testFailedCommitLeavesNoEventsForTheNextCommit(): void
    {
        $this->connection->beginTransaction();
        $this->entityManager->persist($this->plannedAnime('A'));
        $this->entityManager->flush();
        self::assertTrue($this->publisher->hasReady());

        $this->connection->setRollbackOnly();
        try {
            $this->connection->commit();
            self::fail('The commit must fail.');
        } catch (\Throwable) {
        }
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::assertFalse($this->publisher->hasReady());
        $this->rawEntityManager->clear();
        self::assertSame([], $this->log);

        $this->entityManager->persist($this->plannedAnime('B'));
        $this->entityManager->flush();

        self::assertSame(['event(tx=none)'], $this->log);
    }

    public function testSubscriberFailureIsIsolatedAndLogged(): void
    {
        $this->dispatcher->addListener(WatchProgressChangedManuallyEvent::class, static function (): void {
            throw new \RuntimeException('subscriber failed');
        }, 10);

        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->persist($this->plannedAnime('A'));
            $this->entityManager->flush();
        });

        self::assertSame(['event(tx=none)'], $this->log, 'The other subscriber still got the event.');
        self::assertCount(1, $this->logger->messages);
    }

    public function testSubscriberThatCommitsDuringDeliveryNeitherDuplicatesNorLosesEvents(): void
    {
        $second = $this->plannedAnime('Second');
        $this->entityManager->persist($second);
        $this->entityManager->flush();
        $this->log = [];

        $titles = [];
        $this->dispatcher->addListener(WatchProgressChangedManuallyEvent::class, function (WatchProgressChangedManuallyEvent $event) use ($second, &$titles): void {
            $titles[] = $event->id;
            if (\count($titles) > 1) {
                return;
            }
            $this->connection->beginTransaction();
            $second->changeWatchStatusManually(WatchStatus::Watching);
            $this->entityManager->flush();
            $this->connection->commit();
        });

        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->persist($this->plannedAnime('First'));
            $this->entityManager->flush();
        });

        // The first anime's event, then the one raised by the subscriber's own transaction.
        self::assertCount(2, $titles);
        self::assertCount(2, array_unique($titles));
        self::assertCount(2, $this->log);
    }

    public function testResetClearsBothBuffers(): void
    {
        $this->publisher->collect([static fn (): object => new \stdClass()]);
        $this->publisher->reset();
        $this->publisher->promote();
        self::assertFalse($this->publisher->hasReady());

        $this->publisher->collect([static fn (): object => new \stdClass()]);
        $this->publisher->promote();
        self::assertTrue($this->publisher->hasReady());
        $this->publisher->reset();
        self::assertFalse($this->publisher->hasReady());
    }

    public function testPlainConnectionCommitAndRollbackDoNotTouchTheBuffer(): void
    {
        $queue = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::assertNotInstanceOf(DomainEventConnection::class, $queue);

        $this->connection->beginTransaction();
        $this->entityManager->persist($this->plannedAnime('A'));
        $this->entityManager->flush();
        self::assertTrue($this->publisher->hasReady());

        $queue->beginTransaction();
        $queue->commit();
        $queue->beginTransaction();
        $queue->rollBack();

        self::assertTrue($this->publisher->hasReady());
        self::assertSame([], $this->log);
        $this->connection->commit();
        self::assertSame(['event(tx=none)'], $this->log);
    }

    #[DataProvider('safetyNetEvents')]
    public function testSafetyNetDeliversEventsLeftByAFlushBypassingTheDecoratorAndResets(string $eventName): void
    {
        $this->dispatcher->addSubscriber(new DomainEventSafetyNetSubscriber($this->publisher));

        // Flushed on the raw manager, as a Doctrine listener would via $args->getObjectManager().
        $this->rawEntityManager->persist($this->plannedAnime('A'));
        $this->rawEntityManager->flush();
        self::assertSame([], $this->log);
        self::assertTrue($this->publisher->hasReady());

        $this->dispatchLifecycleEvent($eventName);
        self::assertSame(['event(tx=none)'], $this->log);
        self::assertFalse($this->publisher->hasReady());

        $this->dispatchLifecycleEvent($eventName);
        self::assertSame(['event(tx=none)'], $this->log, 'No second delivery.');
    }

    /** @return iterable<string, array{string}> */
    public static function safetyNetEvents(): iterable
    {
        yield 'kernel.terminate' => ['kernel'];
        yield 'console.terminate' => ['console'];
        yield 'worker handled' => ['handled'];
        yield 'worker failed' => ['failed'];
    }

    private function dispatchLifecycleEvent(string $kind): void
    {
        $envelope = new Envelope(new \stdClass());
        match ($kind) {
            'kernel' => $this->dispatcher->dispatch(
                new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), new Response()),
                \Symfony\Component\HttpKernel\KernelEvents::TERMINATE,
            ),
            'console' => $this->dispatcher->dispatch(
                new ConsoleTerminateEvent(new Command('x'), new ArrayInput([]), new NullOutput(), 0),
                ConsoleEvents::TERMINATE,
            ),
            'handled' => $this->dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async')),
            'failed' => $this->dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('x'))),
            default => throw new \InvalidArgumentException($kind),
        };
    }

    private function plannedAnime(string $title): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle($title)->changeWatchStatusManually(WatchStatus::Plan);

        return $anime;
    }

    private function unchangedAnime(string $title): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);

        return $anime;
    }

    private function studio(string $name): Studio
    {
        $studio = new Studio();
        $studio->rename($name);

        return $studio;
    }
}
