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

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Statically known shape of the fixture controller below, so the container's
 * `get()`-returned service can be narrowed for static analysis before calling into it — the
 * fixture class itself only exists once written to a plugin's isolated `src/` directory at
 * runtime and is invisible to PHPStan.
 */
interface SettingsStoreControllerFixtureInterface
{
    public function readStoredNote(): string;
}

/**
 * End-to-end coverage for {@see \App\Service\Plugin\DependencyInjection\Compiler\SettingsStoreScopePass}
 * (issue #583): a real, cold-compiled container the same way {@see \App\Kernel} boots in
 * production, with an installed integration plugin whose *controller* — not a plain widget class
 * — injects `SettingsStoreInterface` into its constructor, the same shape Shikimori's OAuth/
 * settings controllers use.
 *
 * The fixture controller extends `Symfony\Bundle\FrameworkBundle\Controller\AbstractController`
 * deliberately: `FrameworkExtension` calls `registerForAutoconfiguration(AbstractController::class)`,
 * which is what makes Symfony's `ResolveInstanceofConditionalsPass` split the plugin's autoconfigured
 * definition in two — a real service definition plus a companion, argument-less
 * `.abstract.instanceof.<class>` definition holding the merged `_instanceof` rules. The existing
 * `PluginDataAndSettingsStoreWidgetBootTest`/`CatalogReaderWidgetBootTest` fixtures are plain classes
 * with no `_instanceof`-matching ancestor, so that abstract definition never appears in their
 * container and they cannot catch this defect.
 *
 * Before the fix, `SettingsStoreScopePass` (and its `PluginDataStoreScopePass`/
 * `CatalogReaderScopePass` siblings) looped over every definition without skipping
 * `Definition::isAbstract()`, so the binding also landed on `.abstract.instanceof.<class>` — a
 * definition with no arguments of its own. Symfony's `ResolveBindingsPass` hard-fails compilation
 * on a binding with no matching argument on that definition
 * (`Symfony\Component\DependencyInjection\Exception\InvalidArgumentException`, "A binding is
 * configured for an argument of type ... but no corresponding argument has been found."), thrown
 * out of `self::bootKernel()`, before any request is handled.
 *
 * `DATABASE_URL`/`QUEUE_DATABASE_URL` are pointed at throwaway SQLite files, both `$_SERVER` and
 * `$_ENV` (Symfony resolves `%env(...)%` from `$_ENV` first, see
 * {@see SettingsLocaleSwitchAcceptanceTest}'s docblock and `.claude-docs/gotchas.md`), the same
 * defensive isolation as every other cold-compile boot test in this suite — this test never
 * touches the database itself, but booting the real container still wires up the Doctrine bundle.
 */
final class SettingsStoreControllerBootTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const PLUGIN_ID = 'acme-settings-controller';

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

        $this->runtimeDir = $this->createTemporaryDirectory('anime-settings-store-controller-boot-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-settings-store-controller-boot-plugins-');
        $this->databasePath = sys_get_temp_dir().'/anime-settings-store-controller-boot-db-'.uniqid().'.sqlite';
        $this->queueDatabasePath = sys_get_temp_dir().'/anime-settings-store-controller-boot-queue-'.uniqid().'.sqlite';

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

    public function testContainerCompilesAndControllerUsesScopedSettingsStore(): void
    {
        $this->writeControllerPluginFixture(self::PLUGIN_ID, 'AcmeSettingsController', 'PluginSettingsController');
        $this->reconcilePlugins();

        self::bootKernel(['debug' => false]);

        $controller = self::getContainer()->get('AnimeDb\Plugins\AcmeSettingsController\PluginSettingsController');
        self::assertInstanceOf(SettingsStoreControllerFixtureInterface::class, $controller);

        self::assertSame('stored', $controller->readStoredNote());
    }

    private function writeControllerPluginFixture(string $pluginId, string $studlyVendor, string $controllerClassName): void
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

        file_put_contents($dir.'/src/'.$controllerClassName.'.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\{$studlyVendor};

            use AnimeDb\\PluginContracts\\Settings\\SettingsStoreInterface;
            use App\\Tests\\Acceptance\\SettingsStoreControllerFixtureInterface;
            use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;

            final class {$controllerClassName} extends AbstractController implements SettingsStoreControllerFixtureInterface
            {
                public function __construct(
                    private readonly SettingsStoreInterface \$settingsStore,
                ) {
                }

                public function readStoredNote(): string
                {
                    \$this->settingsStore->update(static fn (array \$settings): array => [...\$settings, 'note' => 'stored']);

                    return \$this->settingsStore->read()['note'] ?? 'NONE';
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
