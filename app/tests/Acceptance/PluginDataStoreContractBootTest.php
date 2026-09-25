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
use AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface;
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
 * End-to-end coverage for issue #789: a plugin service whose constructor type-hints the
 * *contracts* `AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface` must get a store
 * scoped to its own plugin id from a real, cold-compiled container. Two installed plugins each
 * hold their own instance, so the assertions below also prove that one plugin never sees the
 * other's payload slice.
 */
final class PluginDataStoreContractBootTest extends KernelTestCase
{
    use TemporaryDirectories;

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
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        $this->originalDatabaseUrlEnv = $_ENV['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;
        $this->originalCoreVersion = $_SERVER['CORE_VERSION'] ?? null;

        $runtimeDir = $this->createTemporaryDirectory('anime-plugin-data-contract-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-plugin-data-contract-plugins-');
        $this->databasePath = sys_get_temp_dir().'/anime-plugin-data-contract-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-plugin-data-contract-queue-'.uniqid().'.sqlite';

        $_SERVER['APP_RUNTIME_DIR'] = $runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$this->databasePath;
        $_SERVER['QUEUE_DATABASE_URL'] = $_ENV['QUEUE_DATABASE_URL'] = 'sqlite:///'.$this->queueDatabasePath;
        // Same Electron-supplied channel native/supervisor/env.js sets in production (issue #565);
        // the fixture manifests below require core >=2.0.0.
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

    public function testPluginReceivesStoreScopedToItsOwnIdAndDoesNotSeeAnotherPluginsSlice(): void
    {
        $animeId = $this->bootWithTwoPlugins();

        $alpha = $this->injectedStore('AcmeAlpha');
        $beta = $this->injectedStore('AcmeBeta');

        // The instance handed to each plugin is the one the scope pass built for its own id.
        self::assertSame(self::getContainer()->get('app.plugin_data_store.acme-alpha'), $alpha);
        self::assertSame(self::getContainer()->get('app.plugin_data_store.acme-beta'), $beta);

        $alpha->write($animeId, ['owner' => 'alpha']);
        $beta->write($animeId, ['owner' => 'beta']);

        self::assertSame(['owner' => 'alpha'], $alpha->read($animeId));
        self::assertSame(['owner' => 'beta'], $beta->read($animeId));
    }

    public function testPluginReadingThroughItsOwnStoreGetsEmptyArrayForPayloadWrittenByAnotherPlugin(): void
    {
        $animeId = $this->bootWithTwoPlugins();

        $this->injectedStore('AcmeAlpha')->write($animeId, ['secret' => 'alpha-only']);

        self::assertSame([], $this->injectedStore('AcmeBeta')->read($animeId));
    }

    /**
     * The contracts-typed store the container injected into the plugin's fixture service.
     */
    private function injectedStore(string $studlyVendor): PluginDataStoreInterface
    {
        $keeper = self::getContainer()->get('AnimeDb\\Plugins\\'.$studlyVendor.'\\PayloadKeeper');

        $store = (new \ReflectionProperty($keeper, 'store'))->getValue($keeper);
        self::assertInstanceOf(PluginDataStoreInterface::class, $store);

        return $store;
    }

    private function bootWithTwoPlugins(): ContractAnimeId
    {
        $this->writePluginFixture('acme-alpha', 'AcmeAlpha');
        $this->writePluginFixture('acme-beta', 'AcmeBeta');
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Persisting the Anime fires AnimeSearchIndexListener onto the `async` transport, which
        // has no auto_setup — see PluginDataAndSettingsStoreWidgetBootTest.
        $asyncTransport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(SetupableTransportInterface::class, $asyncTransport);
        $asyncTransport->setup();

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $entityManager->persist($anime);
        $entityManager->flush();

        return new ContractAnimeId($anime->id ?? throw new \LogicException('Anime must have an id after persisting.'));
    }

    /**
     * The fixture implements `EntryWidgetInterface` only so that `TagPluginServicesPass` tags it
     * and it survives unused-private-service pruning (same trick as the sibling boot tests).
     */
    private function writePluginFixture(string $pluginId, string $studlyVendor): void
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

        file_put_contents($dir.'/src/PayloadKeeper.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use AnimeDb\\PluginContracts\\Model\\AnimeId;
            use AnimeDb\\PluginContracts\\PluginData\\PluginDataStoreInterface;
            use AnimeDb\\PluginContracts\\Widget\\EntryWidgetInterface;
            use AnimeDb\\PluginContracts\\Widget\\WidgetMetadata;

            final class PayloadKeeper implements EntryWidgetInterface
            {
                public function __construct(
                    private readonly PluginDataStoreInterface \$store,
                ) {
                }

                public static function metadata(): WidgetMetadata
                {
                    return new WidgetMetadata('PayloadKeeper', 'widget.title', 'widget.description');
                }

                public function resolveExternalId(array \$urls): ?string
                {
                    return null;
                }

                public function render(AnimeId \$anime): string
                {
                    return '';
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
