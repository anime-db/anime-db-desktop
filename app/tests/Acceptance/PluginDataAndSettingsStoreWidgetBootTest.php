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

use AnimeDb\PluginContracts\Model\AnimeId as ContractAnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

/**
 * End-to-end coverage for {@see \App\Service\Plugin\DependencyInjection\Compiler\PluginDataStoreScopePass}
 * and {@see \App\Service\Plugin\DependencyInjection\Compiler\SettingsStoreScopePass} (issue #580): a
 * real, cold-compiled container the same way {@see \App\Kernel} boots in production, with an
 * installed integration plugin whose widget injects both `PluginDataStoreInterface` and
 * `SettingsStoreInterface` into its constructor — the same shape Shikimori's widgets/clients use.
 *
 * Before the fix, both scope passes registered their per-plugin `PluginDataStore`/`SettingsStore`
 * definition with a raw `new PluginId($pluginId)` constructor argument. `PhpDumper` cannot dump a
 * raw object argument and throws `Symfony\Component\DependencyInjection\Exception\RuntimeException`
 * ("Unable to dump a service container if a parameter is an object...") the moment any plugin
 * actually consumes either interface — thrown out of `self::bootKernel()`, before any request is
 * handled. A cold compile is required for the same reason as
 * {@see CatalogReaderWidgetBootTest}: reusing a pre-existing compiled container would never
 * actually exercise the fixture plugin below.
 *
 * `DATABASE_URL`/`QUEUE_DATABASE_URL` are pointed at throwaway SQLite files, both `$_SERVER` and
 * `$_ENV` (Symfony resolves `%env(...)%` from `$_ENV` first, see
 * {@see SettingsLocaleSwitchAcceptanceTest}'s docblock and `.claude-docs/gotchas.md`) — otherwise
 * persisting the fixture Anime below would write into the app's real working database.
 * Persisting it also fires `AnimeSearchIndexListener`, which dispatches onto the `async` Messenger
 * transport (unrelated to this test's actual subject) — see the explicit
 * `messenger.transport.async` setup() call below for why.
 */
final class PluginDataAndSettingsStoreWidgetBootTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const PLUGIN_ID = 'acme-scoped';

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
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        $this->originalDatabaseUrlEnv = $_ENV['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;

        $this->runtimeDir = $this->createTemporaryDirectory('anime-plugin-data-settings-boot-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-plugin-data-settings-boot-plugins-');
        $this->databasePath = sys_get_temp_dir().'/anime-plugin-data-settings-boot-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-plugin-data-settings-boot-queue-'.uniqid().'.sqlite';

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

        $this->removeTemporaryDirectories();
    }

    public function testContainerCompilesAndWidgetUsesBothScopedStores(): void
    {
        $this->writeWidgetPluginFixture(self::PLUGIN_ID, 'AcmeScoped', 'ScopedWidget');
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Guards against a silently ignored $_SERVER-only override (see .claude-docs/gotchas.md).
        self::assertSame(
            $this->databasePath,
            $entityManager->getConnection()->getParams()['path'] ?? null,
            'The entity manager must be connected to this test\'s throwaway SQLite file, not the app\'s working database.',
        );

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Persisting the fixture Anime below fires AnimeSearchIndexListener, which dispatches
        // onto the `async` transport (unrelated to this test's actual subject) — messenger.yaml
        // has no auto_setup, so the messenger_messages table needs an explicit setup() here, the
        // same as `bin/console messenger:setup-transports` does in a real install.
        $asyncTransport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(SetupableTransportInterface::class, $asyncTransport);
        $asyncTransport->setup();

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $entityManager->persist($anime);
        $entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        $widget = self::getContainer()->get('AnimeDb\Plugins\AcmeScoped\ScopedWidget');
        self::assertInstanceOf(EntryWidgetInterface::class, $widget);

        self::assertSame(
            'data=stored;settings=stored',
            $widget->render(new ContractAnimeId($animeId)),
        );
    }

    private function writeWidgetPluginFixture(string $pluginId, string $studlyVendor, string $widgetClassName): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/src', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        file_put_contents($dir.'/src/'.$widgetClassName.'.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use AnimeDb\\PluginContracts\\Model\\AnimeId;
            use AnimeDb\\PluginContracts\\Settings\\SettingsStoreInterface;
            use AnimeDb\\PluginContracts\\Widget\\EntryWidgetInterface;
            use AnimeDb\\PluginContracts\\Widget\\WidgetMetadata;
            use App\\Service\\Plugin\\PluginDataStoreInterface;

            final class {$widgetClassName} implements EntryWidgetInterface
            {
                public function __construct(
                    private readonly PluginDataStoreInterface \$pluginDataStore,
                    private readonly SettingsStoreInterface \$settingsStore,
                ) {
                }

                public static function metadata(): WidgetMetadata
                {
                    return new WidgetMetadata('{$widgetClassName}', 'widget.title', 'widget.description');
                }

                public function resolveExternalId(array \$urls): ?string
                {
                    return null;
                }

                public function render(AnimeId \$anime): string
                {
                    \$this->pluginDataStore->write(\$anime, ['note' => 'stored']);
                    \$this->settingsStore->update(static fn (array \$settings): array => [...\$settings, 'note' => 'stored']);

                    \$data = \$this->pluginDataStore->read(\$anime)['note'] ?? 'NONE';
                    \$settings = \$this->settingsStore->read()['note'] ?? 'NONE';

                    return "data={\$data};settings={\$settings}";
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
