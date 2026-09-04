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

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Entity\Enum\WatchStatus;
use App\Event\WatchProgressChangedManuallyEvent;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * End-to-end coverage for issue #579: before this fix, {@see \App\Service\Plugin\PluginLoader}
 * only wired up {@see \AnimeDb\PluginContracts\Manifest\PluginType::Integration} plugins — a
 * plugin declaring `type: local` in its manifest installed and showed up enabled, but its `src/`
 * was never autoloaded and its classes never reached the container, silently. A unit test on
 * `PluginLoader` alone (see {@see \App\Tests\Unit\Service\Plugin\PluginLoaderTest}) cannot catch
 * this class of hole: it only exercises the path list/bundle list `PluginLoader` builds, never
 * whether a real `Kernel::configureContainer()` boot actually turns that list into services — the
 * same reasoning `CatalogReaderWidgetBootTest`/`PluginTranslationBootTest` already document for
 * their own subjects.
 *
 * `DATABASE_URL`/`QUEUE_DATABASE_URL` are pointed at throwaway SQLite files, both `$_SERVER` and
 * `$_ENV`, the same way {@see CatalogReaderWidgetBootTest} does: dispatching
 * `WatchProgressChangedManuallyEvent` below also reaches the core's own
 * `App\EventSubscriber\WatchProgressPushSubscriber`, which dispatches onto the `async` Messenger
 * transport — unrelated to this test's actual subject, but real enough that it needs a real
 * `messenger_messages` table to write into.
 *
 * A cold compile is required for the same reason as {@see PluginTranslationBootTest}: reusing a
 * pre-existing compiled container would never actually exercise the fixture plugin below.
 */
final class LocalPluginServiceBootTest extends KernelTestCase
{
    private const PLUGIN_ID = 'acme-local-widget';
    private const STUDLY_VENDOR = 'AcmeLocalWidget';
    private const WIDGET_CLASS_NAME = 'LocalWidget';
    private const SUBSCRIBER_CLASS_NAME = 'LocalCatalogEventSubscriber';

    /**
     * Set by the fixture plugin's event subscriber (via a fully-qualified call to
     * {@see self::recordCatalogEvent()}) to prove a real dispatch reached it — the fixture class
     * itself is loaded dynamically by the plugin autoloader, so it cannot return anything to the
     * test directly; this static flag is the simplest channel back, reset per test in
     * {@see self::setUp()}.
     */
    private static bool $catalogEventReceived = false;

    private string $runtimeDir;
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

    protected function setUp(): void
    {
        self::$catalogEventReceived = false;

        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        $this->originalDatabaseUrlEnv = $_ENV['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;

        $this->runtimeDir = sys_get_temp_dir().'/anime-local-plugin-boot-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-local-plugin-boot-plugins-'.uniqid();
        $this->databasePath = sys_get_temp_dir().'/anime-local-plugin-boot-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-local-plugin-boot-queue-'.uniqid().'.sqlite';
        mkdir($this->runtimeDir, recursive: true);
        mkdir($this->pluginsDir, recursive: true);

        $_SERVER['APP_RUNTIME_DIR'] = $this->runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$this->databasePath;
        $_SERVER['QUEUE_DATABASE_URL'] = $_ENV['QUEUE_DATABASE_URL'] = 'sqlite:///'.$this->queueDatabasePath;
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

        $this->removeDirectory($this->runtimeDir);
        $this->removeDirectory($this->pluginsDir);
    }

    public function testLocalPluginServiceIsAutoloadedRegisteredAndReceivesCatalogEvents(): void
    {
        $this->writeLocalPluginFixture();
        $this->reconcilePlugins();

        self::bootKernel();

        $widgetServiceId = 'AnimeDb\\Plugins\\'.self::STUDLY_VENDOR.'\\'.self::WIDGET_CLASS_NAME;
        $subscriberServiceId = 'AnimeDb\\Plugins\\'.self::STUDLY_VENDOR.'\\'.self::SUBSCRIBER_CLASS_NAME;

        self::assertTrue(
            self::getContainer()->has($widgetServiceId) && self::getContainer()->has($subscriberServiceId),
            'A `local`-type plugin\'s src/ classes must be autoloaded and registered as services, exactly like an `integration` plugin\'s.',
        );

        $widget = self::getContainer()->get($widgetServiceId);
        self::assertInstanceOf(EntryWidgetInterface::class, $widget);

        // Widget declared by the local plugin: rendering it proves TagPluginServicesPass tagged
        // it as app.entry_widget and the widget registry can resolve it, same as an integration
        // plugin's widget.
        self::assertSame('local-widget-markup', $widget->render(new AnimeId(1)));

        // Catalog event subscription: the local plugin's service also implements
        // EventSubscriberInterface, so a real dispatch of a catalog domain event must reach it —
        // confirming autoconfigure() tagged it as kernel.event_subscriber, not just that it exists.
        self::assertFalse(self::$catalogEventReceived, 'Test isolation guard: nothing must have recorded an event yet.');

        // messenger.yaml has no auto_setup, so the messenger_messages table needs an explicit
        // setup() here, the same as `bin/console messenger:setup-transports` does in a real
        // install — WatchProgressPushSubscriber (core) dispatches onto this transport too.
        $asyncTransport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(SetupableTransportInterface::class, $asyncTransport);
        $asyncTransport->setup();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new WatchProgressChangedManuallyEvent(1, WatchStatus::Watching, null));

        self::assertTrue(self::$catalogEventReceived, 'The local plugin\'s subscriber must receive a dispatched catalog event.');
    }

    public static function recordCatalogEvent(): void
    {
        self::$catalogEventReceived = true;
    }

    private function writeLocalPluginFixture(): void
    {
        $dir = $this->pluginsDir.'/'.self::PLUGIN_ID;
        mkdir($dir.'/src', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => self::PLUGIN_ID,
            'name' => ucfirst(self::PLUGIN_ID),
            'version' => '1.0.0',
            'type' => 'local',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $studlyVendor = self::STUDLY_VENDOR;
        $widgetClassName = self::WIDGET_CLASS_NAME;
        $subscriberClassName = self::SUBSCRIBER_CLASS_NAME;

        file_put_contents($dir.'/src/'.$widgetClassName.'.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use AnimeDb\\PluginContracts\\Model\\AnimeId;
            use AnimeDb\\PluginContracts\\Widget\\EntryWidgetInterface;
            use AnimeDb\\PluginContracts\\Widget\\WidgetMetadata;

            final class {$widgetClassName} implements EntryWidgetInterface
            {
                public static function metadata(): WidgetMetadata
                {
                    return new WidgetMetadata('{$widgetClassName}', 'widget.title', 'widget.description');
                }

                public function render(AnimeId \$anime): string
                {
                    return 'local-widget-markup';
                }
            }

            PHP);

        file_put_contents($dir.'/src/'.$subscriberClassName.'.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use App\\Event\\WatchProgressChangedManuallyEvent;
            use App\\Tests\\Acceptance\\LocalPluginServiceBootTest;
            use Symfony\\Component\\EventDispatcher\\EventSubscriberInterface;

            final class {$subscriberClassName} implements EventSubscriberInterface
            {
                public static function getSubscribedEvents(): array
                {
                    return [WatchProgressChangedManuallyEvent::class => 'onCatalogEvent'];
                }

                public function onCatalogEvent(WatchProgressChangedManuallyEvent \$event): void
                {
                    LocalPluginServiceBootTest::recordCatalogEvent();
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
