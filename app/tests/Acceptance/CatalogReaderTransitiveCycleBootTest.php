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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end coverage for the transitive cycle {@see \App\Service\Plugin\DependencyInjection\Compiler\CatalogReaderScopePass}
 * would otherwise miss (issue #577 follow-up): the pass only skipped a resolver candidate whose
 * *own* constructor type-hinted `CatalogReaderInterface` directly, which caught only a one-level
 * cycle. A plugin's resolver (`app.filler`/`app.sync`/`app.search_by_plugin`) can depend on any
 * number of intermediate collaborators, and a cycle through one of those never showed up in that
 * one-level check.
 *
 * This fixture reproduces exactly that: `TransitiveFiller` (tagged `app.filler`, so eligible as
 * its plugin's resolver) does not type-hint `CatalogReaderInterface` itself, but is autowired
 * against `CatalogHelper`, a plain collaborator of the same plugin that does. Before the resolver
 * reference was wrapped in a `ServiceClosureArgument`, compiling this container threw
 * `ServiceCircularReferenceException` with the path
 * "TransitiveFiller -> CatalogHelper -> app.catalog_reader.acme-tr3 -> TransitiveFiller" —
 * confirmed by temporarily reverting the `ServiceClosureArgument` wrapping and re-running this
 * test. A cold compile is required for the same reason as
 * {@see CatalogReaderWidgetBootTest}: nothing about this cycle is visible from a
 * `ContainerBuilder`-level unit test built by hand, since autowiring the plugin classes against
 * each other is what actually forms the graph edges.
 */
final class CatalogReaderTransitiveCycleBootTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const PLUGIN_ID = 'acme-tr3';

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
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrl = $_SERVER['QUEUE_DATABASE_URL'] ?? null;
        $this->originalDatabaseUrlEnv = $_ENV['DATABASE_URL'] ?? null;
        $this->originalQueueDatabaseUrlEnv = $_ENV['QUEUE_DATABASE_URL'] ?? null;
        $this->originalCoreVersion = $_SERVER['CORE_VERSION'] ?? null;

        $this->runtimeDir = $this->createTemporaryDirectory('anime-catalog-reader-cycle-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-catalog-reader-cycle-plugins-');
        $this->databasePath = sys_get_temp_dir().'/anime-catalog-reader-cycle-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-catalog-reader-cycle-queue-'.uniqid().'.sqlite';

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

    public function testContainerCompilesWhenTheResolverDependsOnACollaboratorThatConsumesCatalogReader(): void
    {
        $this->writeTransitiveFillerFixture();
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        $filler = self::getContainer()->get('AnimeDb\Plugins\AcmeTr3\TransitiveFiller');
        self::assertInstanceOf(FillerInterface::class, $filler);
    }

    private function writeTransitiveFillerFixture(): void
    {
        $dir = $this->pluginsDir.'/'.self::PLUGIN_ID;
        mkdir($dir.'/src', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => self::PLUGIN_ID,
            'name' => 'AcmeTr3',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        file_put_contents($dir.'/src/CatalogHelper.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AnimeDb\Plugins\AcmeTr3;

            use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;

            final class CatalogHelper
            {
                public function __construct(
                    private readonly CatalogReaderInterface $catalogReader,
                ) {
                }
            }

            PHP);

        file_put_contents($dir.'/src/TransitiveFiller.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AnimeDb\Plugins\AcmeTr3;

            use AnimeDb\PluginContracts\Filler\FillerInterface;
            use AnimeDb\PluginContracts\Filler\PluginAnimeData;

            final class TransitiveFiller implements FillerInterface
            {
                public function __construct(
                    private readonly CatalogHelper $helper,
                ) {
                }

                public function resolveExternalId(array $urls): ?string
                {
                    return null;
                }

                public function find(string $name, ?callable $onHeartbeat = null): array
                {
                    return [];
                }

                public function findById(string $externalId): ?PluginAnimeData
                {
                    return null;
                }

                public function getFillableFields(): array
                {
                    return [];
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
