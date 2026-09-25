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

namespace App\Tests\Acceptance;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use App\Message\RunPluginBackgroundTaskMessage;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;

/**
 * End-to-end coverage for issue #702 (part 2 of 3 for #684): a real, cold-compiled container the
 * same way {@see \App\Kernel} boots in production, with an installed plugin that both submits a
 * background task through its own scoped `BackgroundTaskQueueInterface` and declares the
 * `BackgroundTaskHandlerInterface` that must receive it back. Both roles live on the *same*
 * fixture class deliberately: a plugin service that only submits and is never otherwise consumed
 * would be pruned by Symfony's unused-private-service removal before this test could ever fetch
 * it — implementing `BackgroundTaskHandlerInterface` keeps it alive via
 * {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass}'s
 * `app.background_task_handler` tag and {@see \App\Service\Plugin\BackgroundTaskHandlerRegistry}'s
 * `#[AutowireIterator]` reference to it, exactly the way the widget fixture in
 * {@see WidgetEventSubscriberBootTest} stays alive through its own widget tag.
 *
 * Unlike {@see \App\Tests\Unit\MessageHandler\RunPluginBackgroundTaskMessageHandlerTest}, this
 * drives the task through a real {@see MessageBusInterface}, the real `plugins` Doctrine
 * transport (config/packages/messenger.yaml, issue #701) and a real {@see Worker} — the same
 * class `bin/console messenger:consume plugins` uses in production
 * (native/supervisor/plugins-consumer.js) — rather than calling the handler directly, so a
 * regression in routing, transport wiring or handler lookup would be caught here even if it were
 * invisible to a unit test that only exercises the handler class in isolation.
 */
final class BackgroundTaskQueueBootTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const PLUGIN_ID = 'acme-background-task';
    private const STUDLY_VENDOR = 'AcmeBackgroundTask';

    /**
     * Set by the fixture plugin's handler (via a fully-qualified call to
     * {@see self::recordHandledTask()}) to prove the real worker delivered the task back to it —
     * the fixture class is loaded dynamically by the plugin autoloader, so it cannot return
     * anything to the test directly; this static flag is the simplest channel back, reset per
     * test in {@see self::setUp()}.
     */
    private static ?string $handledTaskName = null;

    private string $pluginsDir;
    private string $databasePath;
    private string $queueDatabasePath;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;
    private ?string $originalDatabaseUrl;
    private ?string $originalQueueDatabaseUrl;
    private ?string $originalDatabaseUrlEnv;
    private ?string $originalQueueDatabaseUrlEnv;
    private ?string $originalCoreVersion;

    protected function setUp(): void
    {
        self::$handledTaskName = null;

        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        $this->originalDatabaseUrlEnv = $_ENV['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;
        $this->originalCoreVersion = $_SERVER['CORE_VERSION'] ?? null;

        $runtimeDir = $this->createTemporaryDirectory('anime-background-task-boot-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-background-task-boot-plugins-');
        $this->databasePath = sys_get_temp_dir().'/anime-background-task-boot-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-background-task-boot-queue-'.uniqid().'.sqlite';

        $_SERVER['APP_RUNTIME_DIR'] = $runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$this->databasePath;
        $_SERVER['QUEUE_DATABASE_URL'] = $_ENV['QUEUE_DATABASE_URL'] = 'sqlite:///'.$this->queueDatabasePath;
        // Mocks the same Electron-supplied channel native/supervisor/env.js sets in production
        // (issue #565) — without it, Kernel::coreVersion() would fall back to this checkout's own
        // package.json version, which does not satisfy the fixture manifest's `require.core`
        // below and would keep its plugin bundle from registering at all.
        $_SERVER['CORE_VERSION'] = '2.0.0';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->databasePath, $this->queueDatabasePath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->restoreServerVar('APP_RUNTIME_DIR', $this->originalRuntimeDir);
        $this->restoreServerVar('PLUGINS_DIR', $this->originalPluginsDir);
        $this->restoreServerVar('PLUGINS_CONFIG_PATH', $this->originalPluginsConfigPath);
        $this->restoreServerVar('DATABASE_URL', $this->originalDatabaseUrl);
        $this->restoreServerVar('QUEUE_DATABASE_URL', $this->originalQueueDatabaseUrl);
        $this->restoreEnvVar('DATABASE_URL', $this->originalDatabaseUrlEnv);
        $this->restoreEnvVar('QUEUE_DATABASE_URL', $this->originalQueueDatabaseUrlEnv);
        $this->restoreServerVar('CORE_VERSION', $this->originalCoreVersion);

        $this->removeTemporaryDirectories();
    }

    public function testTaskSubmittedThroughTheScopedQueueReachesThePluginsOwnHandlerViaTheRealBus(): void
    {
        $this->writeFixturePlugin();
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        $taskRunnerServiceId = 'AnimeDb\\Plugins\\'.self::STUDLY_VENDOR.'\\TaskRunner';
        self::assertTrue(self::getContainer()->has($taskRunnerServiceId));

        // messenger.yaml has no auto_setup, so the messenger_messages table needs an explicit
        // setup() here, the same as `bin/console messenger:setup-transports` does in a real
        // install (see WidgetEventSubscriberBootTest for the same pattern on `async`).
        $transport = $this->setUpTransport('messenger.transport.plugins');

        self::assertNull(self::$handledTaskName, 'Test isolation guard: nothing must have recorded a task yet.');

        // The scoped queue instance BackgroundTaskQueueScopePass bound into TaskRunner's own
        // constructor — fetched by the same deterministic per-plugin service id the pass itself
        // creates it under, rather than adding a bespoke "submit for me" method to the fixture
        // class purely for this test to call.
        $queue = self::getContainer()->get('app.background_task_queue.'.self::PLUGIN_ID);
        self::assertInstanceOf(BackgroundTaskQueueInterface::class, $queue);
        $queue->submit(new BackgroundTask('rescan'));

        $this->consumeOnePendingMessage($transport);

        self::assertSame('rescan', self::$handledTaskName);
    }

    /**
     * Confirms {@see RunPluginBackgroundTaskMessage} is routed to the `plugins`
     * transport specifically (config/packages/messenger.yaml routing map) and never ends up on
     * `async` or `media` instead — those two are consumed by the same worker `PushSyncMessage`
     * depends on (issue #366), so a plugin task landing there by mistake would be exactly the
     * "occupy the worker" failure the dedicated `plugins` transport/consumer process (issue #701)
     * exists to prevent. Reads directly off each transport's own `get()` — a single, immediate
     * query, unlike {@see Worker}'s polling loop — so a misrouted message fails the assertion
     * immediately instead of leaving a worker to poll an empty transport forever.
     */
    public function testRoutesOnlyToThePluginsTransportNotAsyncOrMedia(): void
    {
        $this->writeFixturePlugin();
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        $asyncTransport = $this->setUpTransport('messenger.transport.async');
        $mediaTransport = $this->setUpTransport('messenger.transport.media');
        $pluginsTransport = $this->setUpTransport('messenger.transport.plugins');

        $queue = self::getContainer()->get('app.background_task_queue.'.self::PLUGIN_ID);
        self::assertInstanceOf(BackgroundTaskQueueInterface::class, $queue);
        $queue->submit(new BackgroundTask('rescan'));

        $pluginsEnvelopes = iterator_to_array($pluginsTransport->get());
        self::assertCount(1, $pluginsEnvelopes);
        self::assertInstanceOf(RunPluginBackgroundTaskMessage::class, $pluginsEnvelopes[0]->getMessage());

        self::assertCount(0, iterator_to_array($asyncTransport->get()));
        self::assertCount(0, iterator_to_array($mediaTransport->get()));
    }

    /**
     * Reads the option off the transport's real Doctrine connection in a booted container, not
     * from the YAML text. `async` and `media` must keep the Doctrine default (3600).
     */
    public function testPluginsTransportUsesShortenedRedeliverTimeout(): void
    {
        self::bootKernel(['debug' => false]);

        self::assertSame(900, $this->redeliverTimeout('messenger.transport.plugins'));
        self::assertSame(3600, $this->redeliverTimeout('messenger.transport.async'));
        self::assertSame(3600, $this->redeliverTimeout('messenger.transport.media'));
    }

    private function redeliverTimeout(string $serviceId): int
    {
        $transport = self::getContainer()->get($serviceId);
        self::assertInstanceOf(DoctrineTransport::class, $transport);

        $connection = (new \ReflectionProperty(DoctrineTransport::class, 'connection'))->getValue($transport);
        self::assertInstanceOf(Connection::class, $connection);

        $timeout = $connection->getConfiguration()['redeliver_timeout'];
        self::assertIsInt($timeout);

        return $timeout;
    }

    public static function recordHandledTask(string $taskName): void
    {
        self::$handledTaskName = $taskName;
    }

    private function setUpTransport(string $serviceId): TransportInterface
    {
        $transport = self::getContainer()->get($serviceId);
        self::assertInstanceOf(SetupableTransportInterface::class, $transport);
        self::assertInstanceOf(TransportInterface::class, $transport);
        $transport->setup();

        return $transport;
    }

    /**
     * Drives exactly one message off the `plugins` transport through a real {@see Worker} — the
     * same class `bin/console messenger:consume plugins` runs in production — rather than calling
     * the message handler directly. Stops itself as soon as one message is handled or fails, so a
     * transport left empty by a routing regression fails the assertion below instead of hanging.
     */
    private function consumeOnePendingMessage(TransportInterface $transport): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);

        $worker = new Worker(['plugins' => $transport], $bus, $eventDispatcher);

        $stop = static function () use ($worker): void {
            $worker->stop();
        };
        $eventDispatcher->addListener(WorkerMessageHandledEvent::class, $stop);
        $eventDispatcher->addListener(WorkerMessageFailedEvent::class, $stop);

        // A `time_limit` bound, on top of the stop-on-handled/failed listeners above: if a
        // regression ever misroutes App\Message\RunPluginBackgroundTaskMessage away from the
        // `plugins` transport, this transport would sit empty and Worker::run() would otherwise
        // poll it forever instead of failing this test.
        $worker->run(['time_limit' => 5]);

        $eventDispatcher->removeListener(WorkerMessageHandledEvent::class, $stop);
        $eventDispatcher->removeListener(WorkerMessageFailedEvent::class, $stop);
    }

    private function writeFixturePlugin(): void
    {
        $dir = $this->pluginsDir.'/'.self::PLUGIN_ID;
        mkdir($dir.'/src', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => self::PLUGIN_ID,
            'name' => ucfirst(self::PLUGIN_ID),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $studlyVendor = self::STUDLY_VENDOR;

        // A single fixture class covers both roles deliberately — see this class's own docblock
        // for why a submit-only service would never survive Symfony's unused-private-service
        // pruning long enough for this test to fetch it.
        file_put_contents($dir.'/src/TaskRunner.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use AnimeDb\\PluginContracts\\Background\\BackgroundTask;
            use AnimeDb\\PluginContracts\\Background\\BackgroundTaskHandlerInterface;
            use AnimeDb\\PluginContracts\\Background\\BackgroundTaskQueueInterface;
            use App\\Tests\\Acceptance\\BackgroundTaskQueueBootTest;

            final class TaskRunner implements BackgroundTaskHandlerInterface
            {
                public function __construct(
                    private readonly BackgroundTaskQueueInterface \$queue,
                ) {
                }

                public function submit(): void
                {
                    \$this->queue->submit(new BackgroundTask('rescan'));
                }

                public function handle(BackgroundTask \$task): void
                {
                    BackgroundTaskQueueBootTest::recordHandledTask(\$task->name);
                }
            }

            PHP);
    }

    private function reconcilePlugins(): void
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();
    }

    private function restoreServerVar(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original;
        }
    }

    private function restoreEnvVar(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original;
        }
    }
}
