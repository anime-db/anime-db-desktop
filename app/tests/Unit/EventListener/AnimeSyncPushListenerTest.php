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

namespace App\Tests\Unit\EventListener;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\EventListener\AnimeSyncPushListener;
use App\Message\PushSyncMessage;
use App\Service\Sync\PullPushSuppressor;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Exercises the Doctrine lifecycle wiring itself (issue #214): given a preUpdate change set,
 * does the listener dispatch PushSyncMessage only when watchStatus is among the changed
 * fields, and does it stay quiet for both other fields and other entity types.
 */
final class AnimeSyncPushListenerTest extends TestCase
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

    public function testDispatchesPushSyncMessageWhenWatchStatusChanged(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new PushSyncMessage($animeId)))
            ->willReturn(new Envelope(new PushSyncMessage($animeId)));

        $changeSet = ['watchStatus' => [WatchStatus::Plan, WatchStatus::Watching]];
        $listener = new AnimeSyncPushListener($messageBus, new PullPushSuppressor());
        $listener->preUpdate(new PreUpdateEventArgs($anime, $this->entityManager, $changeSet));
    }

    public function testDoesNothingWhenSuppressedByAPullInProgress(): void
    {
        $anime = $this->persistAnime();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $suppressor = new PullPushSuppressor();
        $changeSet = ['watchStatus' => [WatchStatus::Plan, WatchStatus::Watching]];
        $listener = new AnimeSyncPushListener($messageBus, $suppressor);

        $suppressor->suppress(function () use ($listener, $anime, $changeSet): void {
            $listener->preUpdate(new PreUpdateEventArgs($anime, $this->entityManager, $changeSet));
        });
    }

    public function testDoesNothingWhenWatchStatusIsUnchanged(): void
    {
        $anime = $this->persistAnime();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $changeSet = ['title' => ['Cowboy Bebop', 'Cowboy Bebop: Remastered']];
        $listener = new AnimeSyncPushListener($messageBus, new PullPushSuppressor());
        $listener->preUpdate(new PreUpdateEventArgs($anime, $this->entityManager, $changeSet));
    }

    /**
     * Regression test (issue #365, "camp #5"): a SeriesAnime episode-only edit (5/12 -> 6/12
     * while still Watching) never touches watchStatus, so the old hasChangedField('watchStatus')-
     * only check missed it entirely and the episode progress was never pushed to sync plugins.
     */
    public function testDispatchesPushSyncMessageWhenWatchedEpisodesChanged(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new PushSyncMessage($animeId)))
            ->willReturn(new Envelope(new PushSyncMessage($animeId)));

        $changeSet = ['watchedEpisodes' => [5, 6]];
        $listener = new AnimeSyncPushListener($messageBus, new PullPushSuppressor());
        $listener->preUpdate(new PreUpdateEventArgs($anime, $this->entityManager, $changeSet));
    }

    /**
     * Anime::applyWatchProgress() (issue #365) stamps watchProgressUpdatedAt on every
     * successful application, so a future sync-engine call through it is covered by this
     * changed field even on a run where neither watchStatus nor watchedEpisodes moved.
     */
    public function testDispatchesPushSyncMessageWhenWatchProgressUpdatedAtChanged(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new PushSyncMessage($animeId)))
            ->willReturn(new Envelope(new PushSyncMessage($animeId)));

        $changeSet = ['watchProgressUpdatedAt' => [null, new \DateTimeImmutable()]];
        $listener = new AnimeSyncPushListener($messageBus, new PullPushSuppressor());
        $listener->preUpdate(new PreUpdateEventArgs($anime, $this->entityManager, $changeSet));
    }

    public function testDoesNothingForOtherEntityTypes(): void
    {
        $storage = new Storage('Main folder', 'C:\\Anime', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $changeSet = ['name' => ['Main folder', 'Renamed folder']];
        $listener = new AnimeSyncPushListener($messageBus, new PullPushSuppressor());
        $listener->preUpdate(new PreUpdateEventArgs($storage, $this->entityManager, $changeSet));
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
