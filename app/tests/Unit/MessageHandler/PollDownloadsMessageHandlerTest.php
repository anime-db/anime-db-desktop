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

use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Message\PollDownloadsMessage;
use App\MessageHandler\PollDownloadsMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Covers issue #685 end to end at the Messenger layer: dispatching {@see PollDownloadsMessage}
 * through a real {@see MessageBusInterface} — the same mechanism a
 * {@see \App\Scheduler\DownloadsPollSchedule} tick uses once consumed off the
 * `scheduler_downloads_poll` transport — actually links a finished download to its catalog entry
 * and dispatches {@see DownloadCompletedEvent}. This is deliberately not just a unit test of
 * {@see DownloadCompletionPoller::poll()} itself (already covered by
 * {@see \App\Tests\Unit\Service\Download\DownloadCompletionPollerTest}): the bug this issue fixes
 * was that nothing actually triggered poll() on a schedule, so what needs proving here is that a
 * message landing on the bus reaches it.
 */
final class PollDownloadsMessageHandlerTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:18080';
    private const string ROOT = 'C:\\Users\\bob\\Downloads';
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private string $configPath;

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

        $this->downloads = new DownloadRepository($this->entityManager);

        $this->configPath = sys_get_temp_dir().'/anime-poll-downloads-message-handler-test-'.uniqid().'.json';
        file_put_contents($this->configPath, json_encode(['downloadsRoot' => self::ROOT]));
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function makeBus(EventDispatcher $eventDispatcher): MessageBusInterface
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([[
                'hash' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => self::ROOT.'\\finished-release',
            ]], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);

        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $handler = new PollDownloadsMessageHandler($poller);

        return new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                PollDownloadsMessage::class => [$handler],
            ])),
        ]);
    }

    public function testDispatchingThePollMessageOnTheBusLinksTheDownloadAndDispatchesTheEvent(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $dispatchedEvents = [];
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(DownloadCompletedEvent::class, function (DownloadCompletedEvent $event) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = $event;
        });

        $bus = $this->makeBus($eventDispatcher);
        $bus->dispatch(new PollDownloadsMessage());

        $this->assertCount(1, $dispatchedEvents);
        $this->assertSame($anime->id, $dispatchedEvents[0]->anime->value);
        $this->assertSame(self::HASH, $dispatchedEvents[0]->task->value);

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isCompleted());
        $this->assertSame(self::ROOT, $anime->getStorage()?->getPath());
    }

    /**
     * A schedule tick and the app-startup run (native/supervisor/downloads-poll.js) can land back
     * to back — this pins that dispatching the message twice through the bus, not just calling
     * poll() twice directly, still only links/dispatches once (DownloadCompletionPoller's own
     * idempotency, see its class docblock and
     * {@see \App\Tests\Unit\Service\Download\DownloadCompletionPollerTest}).
     */
    public function testDispatchingThePollMessageTwiceDoesNotDispatchTheEventTwice(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $dispatchedEvents = [];
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(DownloadCompletedEvent::class, function (DownloadCompletedEvent $event) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = $event;
        });

        $bus = $this->makeBus($eventDispatcher);
        $bus->dispatch(new PollDownloadsMessage());
        $bus->dispatch(new PollDownloadsMessage());

        $this->assertCount(1, $dispatchedEvents);
    }
}
