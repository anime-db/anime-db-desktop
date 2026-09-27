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

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Entity\Enum\WatchStatus;
use App\Event\WatchProgressChangedManuallyEvent;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * End-to-end coverage for issue #586: a real, cold-compiled container the same way
 * {@see \App\Kernel} boots in production, with an installed plugin whose widget service
 * *also* implements `Symfony\Component\EventDispatcher\EventSubscriberInterface` on the very
 * same class — the plugin shape #579's own use case calls for (a widget that reacts to catalog
 * events), and the one {@see LocalPluginServiceBootTest} deliberately does not cover, since that
 * fixture splits the widget and the subscriber across two separate classes.
 *
 * Symfony's `EventDispatcherExtension` autoconfigures `EventSubscriberInterface` the same way
 * `FrameworkExtension` autoconfigures `AbstractController`/`#[AsController]` (see
 * {@see SettingsStoreControllerBootTest}'s docblock): `ResolveInstanceofConditionalsPass` splits
 * such a service's definition in two — the real service plus a companion, argument-less
 * `.abstract.instanceof.<class>` definition holding the merged `_instanceof` rules. Before the
 * fix, {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass} looped over
 * every definition without skipping `Definition::isAbstract()`, so that companion definition —
 * whose class is the very same widget class — got tagged and keyed by widget name a second time,
 * and {@see \App\Service\Plugin\Exception\DuplicateWidgetNameException} fired for a plugin that
 * never actually declared two widgets.
 *
 * The fixture widget below also type-hints `PluginDataStoreInterface`, `SettingsStoreInterface`,
 * `CatalogReaderInterface` and `OwnManifestInterface` in its constructor, so this single
 * `.abstract.instanceof.<class>` companion definition exercises all five of the compiler passes
 * that iterate `ContainerBuilder::getDefinitions()` on a plugin's namespace
 * ({@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass},
 * {@see \App\Service\Plugin\DependencyInjection\Compiler\PluginDataStoreScopePass},
 * {@see \App\Service\Plugin\DependencyInjection\Compiler\SettingsStoreScopePass},
 * {@see \App\Service\Plugin\DependencyInjection\Compiler\CatalogReaderScopePass} and
 * {@see \App\Service\Plugin\DependencyInjection\Compiler\OwnManifestScopePass}) at once — PR #587
 * review found `OwnManifestScopePass` was the one pass in the family still missing the
 * `Definition::isAbstract()` skip, throwing `InvalidArgumentException` out of `ResolveBindingsPass`
 * for a binding placed on the argument-less companion definition. A fixture that combines all five
 * constructor dependencies on one class keeps a future sixth pass in this family from reaching
 * review unguarded the same way.
 *
 * `DATABASE_URL`/`QUEUE_DATABASE_URL` are pointed at throwaway SQLite files, both `$_SERVER` and
 * `$_ENV`, the same way {@see LocalPluginServiceBootTest} does: dispatching
 * `WatchProgressChangedManuallyEvent` below also reaches the core's own
 * `App\EventSubscriber\WatchProgressPushSubscriber`, which dispatches onto the `async` Messenger
 * transport — unrelated to this test's actual subject, but real enough that it needs a real
 * `messenger_messages` table to write into.
 *
 * A cold compile is required for the same reason as {@see SettingsStoreControllerBootTest}:
 * reusing a pre-existing compiled container would never actually exercise the fixture plugin
 * below, nor trigger `ResolveInstanceofConditionalsPass` against it.
 */
final class WidgetEventSubscriberBootTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const PLUGIN_ID = 'acme-widget-subscriber';
    private const STUDLY_VENDOR = 'AcmeWidgetSubscriber';
    private const WIDGET_CLASS_NAME = 'CatalogAwareWidget';

    /**
     * Set by the fixture plugin's widget/subscriber (via a fully-qualified call to
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
    private ?string $originalCoreVersion;

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
        $this->originalCoreVersion = $_SERVER['CORE_VERSION'] ?? null;

        $this->runtimeDir = $this->createTemporaryDirectory('anime-widget-subscriber-boot-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-widget-subscriber-boot-plugins-');
        $this->databasePath = sys_get_temp_dir().'/anime-widget-subscriber-boot-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-widget-subscriber-boot-queue-'.uniqid().'.sqlite';

        $_SERVER['APP_RUNTIME_DIR'] = $this->runtimeDir;
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

    public function testContainerCompilesWithAWidgetThatIsAlsoAnEventSubscriber(): void
    {
        $this->writeWidgetSubscriberPluginFixture();
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        $widgetServiceId = 'AnimeDb\\Plugins\\'.self::STUDLY_VENDOR.'\\'.self::WIDGET_CLASS_NAME;

        self::assertTrue(self::getContainer()->has($widgetServiceId));

        $widget = self::getContainer()->get($widgetServiceId);
        self::assertInstanceOf(EntryWidgetInterface::class, $widget);

        // Widget declared by the plugin: rendering it proves TagPluginServicesPass tagged it as
        // app.entry_widget exactly once, and the widget registry can resolve it — before the fix,
        // this line was never reached because bootKernel() above already threw
        // DuplicateWidgetNameException.
        self::assertSame('catalog-aware-widget-markup', $widget->render(new AnimeId(1)));

        // OwnManifestScopePass wiring: the widget's constructor also type-hints
        // OwnManifestInterface, so reaching bootKernel() above without an InvalidArgumentException
        // already proves the fix; this additionally confirms the binding resolved to *this*
        // plugin's own manifest, not merely that compilation didn't throw.
        $ownManifest = (new \ReflectionProperty($widget, 'ownManifest'))->getValue($widget);
        self::assertInstanceOf(OwnManifestInterface::class, $ownManifest);
        self::assertSame(self::PLUGIN_ID, $ownManifest->id());

        // Catalog event subscription: the same class also implements EventSubscriberInterface, so
        // a real dispatch of a catalog domain event must reach it — confirming autoconfigure()
        // really did split off the abstract `.abstract.instanceof.<class>` definition that
        // TagPluginServicesPass now skips.
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

        self::assertTrue(self::$catalogEventReceived, 'The widget\'s subscriber method must receive a dispatched catalog event.');
    }

    public static function recordCatalogEvent(): void
    {
        self::$catalogEventReceived = true;
    }

    private function writeWidgetSubscriberPluginFixture(): void
    {
        $dir = $this->pluginsDir.'/'.self::PLUGIN_ID;
        mkdir($dir.'/src', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => self::PLUGIN_ID,
            'name' => ucfirst(self::PLUGIN_ID),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $studlyVendor = self::STUDLY_VENDOR;
        $widgetClassName = self::WIDGET_CLASS_NAME;

        file_put_contents($dir.'/src/'.$widgetClassName.'.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use AnimeDb\\PluginContracts\\Catalog\\CatalogReaderInterface;
            use AnimeDb\\PluginContracts\\Manifest\\OwnManifestInterface;
            use AnimeDb\\PluginContracts\\Model\\AnimeId;
            use AnimeDb\\PluginContracts\\Settings\\SettingsStoreInterface;
            use AnimeDb\\PluginContracts\\Widget\\EntryWidgetInterface;
            use AnimeDb\\PluginContracts\\Widget\\WidgetMetadata;
            use App\\Event\\WatchProgressChangedManuallyEvent;
            use AnimeDb\\PluginContracts\\PluginData\\PluginDataStoreInterface;
            use App\\Tests\\Acceptance\\WidgetEventSubscriberBootTest;
            use Symfony\\Component\\EventDispatcher\\EventSubscriberInterface;

            final class {$widgetClassName} implements EntryWidgetInterface, EventSubscriberInterface
            {
                public function __construct(
                    private readonly PluginDataStoreInterface \$pluginDataStore,
                    private readonly SettingsStoreInterface \$settingsStore,
                    private readonly CatalogReaderInterface \$catalogReader,
                    private readonly OwnManifestInterface \$ownManifest,
                ) {
                }

                public static function metadata(): WidgetMetadata
                {
                    return new WidgetMetadata('{$widgetClassName}', 'widget.title', 'widget.description');
                }

                public function render(AnimeId \$anime): string
                {
                    return 'catalog-aware-widget-markup';
                }

                public static function getSubscribedEvents(): array
                {
                    return [WatchProgressChangedManuallyEvent::class => 'onCatalogEvent'];
                }

                public function onCatalogEvent(WatchProgressChangedManuallyEvent \$event): void
                {
                    WidgetEventSubscriberBootTest::recordCatalogEvent();
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
