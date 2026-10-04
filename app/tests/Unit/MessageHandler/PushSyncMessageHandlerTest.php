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

namespace App\Tests\Unit\MessageHandler;

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Message\PushSyncMessage;
use App\MessageHandler\PushSyncMessageHandler;
use App\Repository\AnimeSyncStateRepository;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Verifies the push handler loads the current entity by id, resolves the external id per
 * active sync plugin and forwards a SyncItem built from the anime's current watchStatus
 * (issue #214) — without a live plugin/network, both doubled here.
 */
final class PushSyncMessageHandlerTest extends TestCase
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

    public function testPushesToEveryActivePluginThatResolvesAnExternalId(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        $sync->expects($this->once())
            ->method('push')
            ->with($this->equalTo(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop')))
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable()));
    }

    public function testSkipsAPluginThatDoesNotRecognizeAnySource(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn(null);
        $sync->expects($this->never())->method('push');

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable()));
    }

    public function testDoesNothingWhenTheAnimeNoLongerExists(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('push');

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);
        $handler(new PushSyncMessage(999, new \DateTimeImmutable()));
    }

    /**
     * Issue #353: a dead OAuth session is not transient, so the handler must not let it fall
     * through to Messenger's retry_strategy/dead-letter path — it is rethrown wrapped as
     * unrecoverable instead, which tells Messenger to accept the message as handled.
     */
    public function testWrapsAReauthRequiredExceptionAsUnrecoverableInsteadOfLettingItRetry(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        $sync->expects($this->once())->method('push')->willThrowException(new ReauthRequiredException('Refresh token is dead.'));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable()));
    }

    /**
     * A transient failure (network error, external source down, ...) keeps the pre-#353
     * behavior: it propagates uncaught, so the `async` transport's own retry_strategy still
     * retries it.
     */
    public function testLetsATransientPushFailurePropagateForMessengersRetryStrategy(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        $sync->expects($this->once())->method('push')->willThrowException(new \RuntimeException('Source is down.'));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source is down.');
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable()));
    }

    /**
     * Issue #868: a message naming plugin A must not touch plugin B, even though both are active
     * and both resolve an external id — each plugin now gets its own message.
     */
    public function testPushesOnlyToThePluginNamedInTheMessage(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
            'animedb-myanimelist' => ['features' => ['sync' => true]],
        ]));

        $syncA = $this->createMock(SyncInterface::class);
        $syncA->method('resolveExternalId')->willReturn('1');
        $syncA->expects($this->once())
            ->method('push')
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'));

        $syncB = $this->createMock(SyncInterface::class);
        $syncB->method('resolveExternalId')->willReturn('1');
        $syncB->expects($this->never())->method('push');

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $syncA, 'animedb-myanimelist' => $syncB],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable(), 'animedb-shikimori'));
    }

    /**
     * Issue #868's central guarantee: plugin A's push() failing must not prevent a *separate*
     * message for plugin B from being processed and updating B's own snapshot — each plugin's
     * message carries its own fate now.
     */
    public function testATransientFailureInOnePluginsMessageDoesNotPreventASeparateMessageForAnotherPluginFromUpdatingItsSnapshot(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
            'animedb-myanimelist' => ['features' => ['sync' => true]],
        ]));

        $syncA = $this->createMock(SyncInterface::class);
        $syncA->method('resolveExternalId')->willReturn('1');
        $syncA->expects($this->once())->method('push')->willThrowException(new \RuntimeException('Source A is down.'));

        $syncB = $this->createMock(SyncInterface::class);
        $syncB->method('resolveExternalId')->willReturn('1');
        $syncB->expects($this->once())
            ->method('push')
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $syncA, 'animedb-myanimelist' => $syncB],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $stateRepository = new AnimeSyncStateRepository($this->entityManager);
        $handler = new PushSyncMessageHandler($this->entityManager, $registry, $stateRepository, new NullLogger(), 300);

        try {
            $handler(new PushSyncMessage($animeId, new \DateTimeImmutable(), 'animedb-shikimori'));
            $this->fail('Expected the transient failure in plugin A to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Source A is down.', $exception->getMessage());
        }

        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable(), 'animedb-myanimelist'));

        $this->assertNotNull($stateRepository->find($anime, 'animedb-myanimelist'));
    }

    /**
     * Issue #868: the plugin named in the message may have been disabled between dispatch and
     * processing — the handler re-checks activity via SyncRegistry::findByPluginId() and skips
     * silently, it does not treat that as a failure.
     */
    public function testSkipsAPluginThatIsNoLongerActiveByTheTimeTheMessageIsProcessed(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => false]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('push');

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable(), 'animedb-shikimori'));
    }

    /**
     * Issue #868's backward-compatibility requirement: a PushSyncMessage already sitting in the
     * queue (data/queue.db) from before $pluginId existed deserializes with that property left
     * uninitialized, not merely unset — unserialize() of a value genuinely built by the old
     * constructor shape is reproduced here rather than just omitting the constructor argument, so
     * this exercises the exact state a real queued message would be in after an app update. The
     * handler must not throw on that uninitialized property and must fall back to pushing to
     * every active plugin, same as before this issue.
     */
    public function testOldFormatMessageWithoutPluginIdPushesToEveryActivePluginWithAnExternalId(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
            'animedb-myanimelist' => ['features' => ['sync' => true]],
        ]));

        $syncA = $this->createMock(SyncInterface::class);
        $syncA->method('resolveExternalId')->willReturn('1');
        $syncA->expects($this->once())
            ->method('push')
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'));

        $syncB = $this->createMock(SyncInterface::class);
        $syncB->method('resolveExternalId')->willReturn('1');
        $syncB->expects($this->once())
            ->method('push')
            ->willReturn(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $syncA, 'animedb-myanimelist' => $syncB],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $stateRepository = new AnimeSyncStateRepository($this->entityManager);
        $handler = new PushSyncMessageHandler($this->entityManager, $registry, $stateRepository, new NullLogger(), 300);

        $serialized = \sprintf(
            'O:27:"App\Message\PushSyncMessage":2:{s:7:"animeId";i:%d;s:12:"dispatchedAt";O:17:"DateTimeImmutable":3:{s:4:"date";s:26:"%s";s:13:"timezone_type";i:1;s:8:"timezone";s:6:"+00:00";}}',
            $animeId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        );

        /** @var PushSyncMessage $oldFormatMessage */
        $oldFormatMessage = unserialize($serialized);
        $this->assertFalse(isset($oldFormatMessage->pluginId), 'The fixture must reproduce an uninitialized $pluginId, not merely unset it.');

        $handler($oldFormatMessage);

        $this->assertNotNull($stateRepository->find($anime, 'animedb-shikimori'));
        $this->assertNotNull($stateRepository->find($anime, 'animedb-myanimelist'));
    }

    /**
     * Issue #868: ReauthRequiredException for the one plugin a message names is wrapped the same
     * way the pre-#868 loop wrapped it for a batch of plugins (issue #353) — still unrecoverable,
     * not retried.
     */
    public function testWrapsAReauthRequiredExceptionForTheNamedPluginAsUnrecoverable(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        $sync->expects($this->once())->method('push')->willThrowException(new ReauthRequiredException('Refresh token is dead.'));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry, new AnimeSyncStateRepository($this->entityManager), new NullLogger(), 300);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $handler(new PushSyncMessage($animeId, new \DateTimeImmutable(), 'animedb-shikimori'));
    }
}
