<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Event\WatchProgressChangedManuallyEvent;
use App\EventSubscriber\WatchProgressPushSubscriber;
use App\Message\PushSyncMessage;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The application half of the domain-event mechanism (issue #371): reacts to
 * WatchProgressChangedManuallyEvent (already covered at the domain layer by AnimeTest/
 * SeriesAnimeTest, and released/dispatched by DomainEventListenerTest) by dispatching
 * PushSyncMessage — the same message the old Doctrine preUpdate listener (AnimeSyncPushListener)
 * used to dispatch directly off a changed-field check.
 */
final class WatchProgressPushSubscriberTest extends TestCase
{
    private EntityManager $entityManager;

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

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testSubscribesToWatchProgressChangedManuallyEvent(): void
    {
        $this->assertSame(
            [WatchProgressChangedManuallyEvent::class => 'onWatchProgressChangedManually'],
            WatchProgressPushSubscriber::getSubscribedEvents(),
        );
    }

    public function testDispatchesPushSyncMessageForTheEventsId(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PushSyncMessage $message) use ($animeId): bool {
                // $dispatchedAt (issue #366's push-on-edit TTL anchor) is stamped from
                // now() at dispatch time, so it cannot be compared for exact equality —
                // "recent" is enough to prove it was actually set to the current time.
                $this->assertSame($animeId, $message->animeId);
                $this->assertLessThan(5, abs((new \DateTimeImmutable())->getTimestamp() - $message->dispatchedAt->getTimestamp()));

                return true;
            }))
            ->willReturn(new Envelope(new PushSyncMessage($animeId, new \DateTimeImmutable())));

        $subscriber = new WatchProgressPushSubscriber($messageBus);
        $subscriber->onWatchProgressChangedManually(
            new WatchProgressChangedManuallyEvent($animeId, WatchStatus::Watching, WatchStatus::Plan),
        );
    }

    public function testThrowsWhenTheEventHasNoId(): void
    {
        $subscriber = new WatchProgressPushSubscriber($this->createMock(MessageBusInterface::class));

        $this->expectException(\LogicException::class);
        $subscriber->onWatchProgressChangedManually(
            new WatchProgressChangedManuallyEvent(null, WatchStatus::Watching, WatchStatus::Plan),
        );
    }

    public function testIsARegularSymfonyEventSubscriber(): void
    {
        $subscriber = new WatchProgressPushSubscriber($this->createStub(MessageBusInterface::class));

        $this->assertInstanceOf(EventSubscriberInterface::class, $subscriber);
    }

    private function persistAnime(): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function requireId(MovieAnime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');
    }
}
