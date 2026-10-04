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

namespace App\Tests\Unit\EventSubscriber;

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Event\WatchProgressChangedManuallyEvent;
use App\EventSubscriber\WatchProgressPushSubscriber;
use App\Message\PushSyncMessage;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
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
 *
 * Issue #868: one message per active plugin, not one message fanning out to all of them, so the
 * tests below pin down that the subscriber enumerates {@see SyncRegistry::allActive()} itself and
 * stamps each dispatched message with that plugin's id.
 */
final class WatchProgressPushSubscriberTest extends TestCase
{
    private EntityManager $entityManager;
    private string $pluginsConfigPath;

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

        $this->pluginsConfigPath = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginsConfigPath, $this->pluginsConfigPath.'.tmp', $this->pluginsConfigPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSubscribesToWatchProgressChangedManuallyEvent(): void
    {
        $this->assertSame(
            [WatchProgressChangedManuallyEvent::class => 'onWatchProgressChangedManually'],
            WatchProgressPushSubscriber::getSubscribedEvents(),
        );
    }

    public function testDispatchesOnePushSyncMessagePerActivePlugin(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
            'animedb-myanimelist' => ['features' => ['sync' => true]],
        ]));

        $registry = new SyncRegistry(
            [
                'animedb-shikimori' => $this->createStub(SyncInterface::class),
                'animedb-myanimelist' => $this->createStub(SyncInterface::class),
            ],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        /** @var list<string> $dispatchedPluginIds */
        $dispatchedPluginIds = [];

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->exactly(2))
            ->method('dispatch')
            ->with($this->callback(function (PushSyncMessage $message) use ($animeId, &$dispatchedPluginIds): bool {
                $this->assertSame($animeId, $message->animeId);
                $this->assertNotNull($message->pluginId);

                $dispatchedPluginIds[] = $message->pluginId;

                return true;
            }))
            ->willReturn(new Envelope(new PushSyncMessage($animeId, new \DateTimeImmutable(), 'animedb-shikimori')));

        $subscriber = new WatchProgressPushSubscriber($messageBus, $registry);
        $subscriber->onWatchProgressChangedManually(
            new WatchProgressChangedManuallyEvent($animeId, WatchStatus::Watching, WatchStatus::Plan),
        );

        $this->assertSame(['animedb-shikimori', 'animedb-myanimelist'], $dispatchedPluginIds);
    }

    public function testDoesNotDispatchForAnInactivePlugin(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => false]],
        ]));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $this->createStub(SyncInterface::class)],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $subscriber = new WatchProgressPushSubscriber($messageBus, $registry);
        $subscriber->onWatchProgressChangedManually(
            new WatchProgressChangedManuallyEvent($animeId, WatchStatus::Watching, WatchStatus::Plan),
        );
    }

    public function testDispatchesNothingWhenNoActivePlugins(): void
    {
        $anime = $this->persistAnime();
        $animeId = $this->requireId($anime);

        $registry = new SyncRegistry(
            [],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $subscriber = new WatchProgressPushSubscriber($messageBus, $registry);
        $subscriber->onWatchProgressChangedManually(
            new WatchProgressChangedManuallyEvent($animeId, WatchStatus::Watching, WatchStatus::Plan),
        );
    }

    public function testThrowsWhenTheEventHasNoId(): void
    {
        $registry = new SyncRegistry([], new PluginsConfigStore($this->pluginsConfigPath));
        $subscriber = new WatchProgressPushSubscriber($this->createStub(MessageBusInterface::class), $registry);

        $this->expectException(\LogicException::class);
        $subscriber->onWatchProgressChangedManually(
            new WatchProgressChangedManuallyEvent(null, WatchStatus::Watching, WatchStatus::Plan),
        );
    }

    public function testIsARegularSymfonyEventSubscriber(): void
    {
        $registry = new SyncRegistry([], new PluginsConfigStore($this->pluginsConfigPath));
        $subscriber = new WatchProgressPushSubscriber($this->createStub(MessageBusInterface::class), $registry);

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
