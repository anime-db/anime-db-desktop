<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginLoader;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Exercises the exact production code path issue #282 adds to
 * {@see \App\Kernel::configureContainer()} — `PluginLoader::integrationPluginServices()` feeding
 * a `ContainerConfigurator::services()->load()` call per plugin — through Symfony's real
 * `PhpFileLoader`/`ContainerBuilder` machinery, the same way `Kernel::configureContainer()` gets
 * invoked on boot.
 *
 * Deliberately not a full {@see \App\Kernel} boot test: the real container also carries
 * `doctrine.orm.validator.unique` (registered unconditionally by doctrine/doctrine-bundle's own
 * `config/orm.php`), whose class extends `Symfony\Component\Validator\ConstraintValidator` — a
 * class from `symfony/validator`, a package this app does not require. A full boot only survives
 * today because every environment happens to reuse an already-compiled container cache; a truly
 * cold compile fatals as soon as anything (like {@see TagPluginServicesPass}) calls
 * `class_exists()` on that service's class. That is a pre-existing landmine unrelated to this
 * issue, so this test recreates just the plugin-relevant slice of the container instead.
 */
final class PluginServiceAutoRegistrationTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-service-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testPluginFillerBecomesAutowiredTaggedServiceWithoutOwnDiConfig(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeFillerClass($pluginId);

        $container = $this->compilePluginServices($pluginId);

        $fillerClass = $this->fillerClass($pluginId);
        $definition = $container->getDefinition($fillerClass);
        $this->assertTrue($definition->isAutowired());
        $this->assertSame([['id' => $pluginId]], $definition->getTag('app.filler'));

        /** @var FillerRegistry $registry */
        $registry = $container->get(FillerRegistry::class);
        $filler = $registry->findByPluginId(new PluginId($pluginId));

        $this->assertNotNull($filler);
        $this->assertSame($fillerClass, $filler::class);
    }

    public function testPluginWithoutSrcDirectoryIsSkippedWithoutBreakingCompilation(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeTranslationManifest($pluginId);
        mkdir($this->pluginsDir.'/'.$pluginId.'/translations', recursive: true);

        // No src/ directory to load services from — compilePluginServices() must not throw, and
        // the container it produces carries no service under this plugin's namespace.
        $container = $this->compilePluginServices($pluginId);

        $this->assertFalse($container->has($this->fillerClass($pluginId)));
    }

    public function testUnreferencedNonServiceClassInPluginSrcIsRemovedByCompilation(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeFillerClass($pluginId);
        $this->writeUnusedClass($pluginId);

        $container = $this->compilePluginServices($pluginId);

        $this->assertFalse($container->has($this->unusedClass($pluginId)));
    }

    private function compilePluginServices(string $pluginId): ContainerBuilder
    {
        $registry = $this->registry();
        $pluginLoader = new PluginLoader($registry, new NullLogger());

        // Mirrors Kernel::initializeBundles(), which registers each integration plugin's
        // autoloader before configureContainer() runs — without it, load() cannot reflect the
        // plugin's classes to build their service definitions.
        $pluginLoader->registerAutoloadForIntegrationPlugins();

        $container = new ContainerBuilder();
        $container->addCompilerPass(new TagPluginServicesPass($registry, new NullLogger()));

        // Real consumer of the 'app.filler' tag (see FillerRegistry's #[AutowireIterator]) — kept
        // public so the test can retrieve it, and so that a plugin Filler service registered below
        // is actually referenced and survives RemoveUnusedDefinitionsPass, same as in production.
        $container->register(PluginsConfigStore::class, PluginsConfigStore::class)
            ->setArguments([$this->pluginsDir.'/plugins.json']);
        $container->register(FillerRegistry::class, FillerRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $loader = new PhpFileLoader($container, new FileLocator($this->pluginsDir));
        $loader->load($this->writePluginServicesConfig($pluginLoader->integrationPluginServices()));

        $container->compile();

        return $container;
    }

    /**
     * Writes a throwaway PHP container config that does exactly what
     * {@see \App\Kernel::configureContainer()} does for every integration plugin, so it goes
     * through the same `ContainerConfigurator`/`PhpFileLoader` machinery Symfony uses on a real
     * boot rather than a hand-rolled substitute.
     *
     * @param array<string, string> $namespaceToSrcDir
     */
    private function writePluginServicesConfig(array $namespaceToSrcDir): string
    {
        $path = $this->pluginsDir.'/container_config.php';
        $lines = [];
        foreach ($namespaceToSrcDir as $namespacePrefix => $srcDir) {
            $lines[] = \sprintf(
                '    $container->services()->defaults()->autowire()->autoconfigure()->load(%s, %s);',
                var_export($namespacePrefix, true),
                var_export($srcDir, true),
            );
        }

        file_put_contents($path, "<?php\n"
            ."use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;\n"
            ."return static function (ContainerConfigurator \$container): void {\n"
            .implode("\n", $lines)."\n"
            ."};\n");

        return $path;
    }

    private function registry(): InstalledPluginsRegistry
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return $registry;
    }

    private function writeIntegrationManifest(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function writeTranslationManifest(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['fr'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function writeFillerClass(string $pluginId): void
    {
        $srcDir = $this->pluginsDir.'/'.$pluginId.'/src';
        mkdir($srcDir, recursive: true);

        $studly = $this->studlyId($pluginId);
        file_put_contents(
            $srcDir.'/'.$studly.'Filler.php',
            '<?php declare(strict_types=1); namespace AnimeDb\Plugins\\'.$studly.';'
            .' use AnimeDb\PluginContracts\Filler\FillerInterface;'
            .' use AnimeDb\PluginContracts\Filler\PluginAnimeData;'
            .' final class '.$studly.'Filler implements FillerInterface {'
            .' public function resolveExternalId(array $urls): ?string { return null; }'
            .' public function find(string $name, ?callable $onHeartbeat = null): array { return []; }'
            .' public function findById(string $externalId): ?PluginAnimeData { return null; }'
            .' public function getFillableFields(): array { return []; }'
            .' }',
        );
    }

    private function writeUnusedClass(string $pluginId): void
    {
        $srcDir = $this->pluginsDir.'/'.$pluginId.'/src';
        if (!is_dir($srcDir)) {
            mkdir($srcDir, recursive: true);
        }

        $studly = $this->studlyId($pluginId);
        file_put_contents(
            $srcDir.'/'.$studly.'UnusedValueObject.php',
            '<?php declare(strict_types=1); namespace AnimeDb\Plugins\\'.$studly.';'
            .' final class '.$studly.'UnusedValueObject {}',
        );
    }

    private function fillerClass(string $pluginId): string
    {
        $studly = $this->studlyId($pluginId);

        return 'AnimeDb\\Plugins\\'.$studly.'\\'.$studly.'Filler';
    }

    private function unusedClass(string $pluginId): string
    {
        $studly = $this->studlyId($pluginId);

        return 'AnimeDb\\Plugins\\'.$studly.'\\'.$studly.'UnusedValueObject';
    }

    private function studlyId(string $pluginId): string
    {
        return str_replace('-', '', ucwords($pluginId, '-'));
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
