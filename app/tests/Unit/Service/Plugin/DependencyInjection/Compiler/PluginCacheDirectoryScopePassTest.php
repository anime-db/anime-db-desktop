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

namespace App\Tests\Unit\Service\Plugin\DependencyInjection\Compiler;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\Plugins\FakeVendor\FakeCacheConsumer;
use AnimeDb\Plugins\FakeVendor\FakeCacheNonConsumer;
use AnimeDb\Plugins\FakeVendorTwo\FakeCacheConsumerTwo;
use App\Service\Plugin\DependencyInjection\Compiler\PluginCacheDirectoryScopePass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheDirectory;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class PluginCacheDirectoryScopePassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $fixtures = __DIR__.'/../../../../../Fixtures/Plugin/PluginCacheDirectoryScopePass/';
        require_once $fixtures.'FakeCacheConsumer.php';
        require_once $fixtures.'FakeCacheConsumerTwo.php';
        require_once $fixtures.'FakeCacheNonConsumer.php';

        $this->pluginsDir = sys_get_temp_dir().'/anime-cache-dir-scope-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testBindsCacheDirectoryOfTheConsumingPlugin(): void
    {
        $this->writeManifest('fake-vendor');

        $container = $this->container();
        $container->register(FakeCacheConsumer::class, FakeCacheConsumer::class);

        $this->pass()->process($container);

        $directory = $this->boundDirectory($container, FakeCacheConsumer::class);
        $this->assertSame(PluginCacheDirectory::class, $directory->getClass());
        $this->assertSame('/runtime/plugin-cache', $container->getParameterBag()->resolveValue('%'.$this->parameterOf($directory).'%'));
        $this->assertSame('fake-vendor', $this->pluginIdOf($directory));
    }

    public function testTwoPluginsGetDifferentDirectories(): void
    {
        $this->writeManifest('fake-vendor');
        $this->writeManifest('fake-vendor-two');

        $container = $this->container();
        $container->register(FakeCacheConsumer::class, FakeCacheConsumer::class);
        $container->register(FakeCacheConsumerTwo::class, FakeCacheConsumerTwo::class);

        $this->pass()->process($container);

        $this->assertSame('fake-vendor', $this->pluginIdOf($this->boundDirectory($container, FakeCacheConsumer::class)));
        $this->assertSame('fake-vendor-two', $this->pluginIdOf($this->boundDirectory($container, FakeCacheConsumerTwo::class)));
    }

    public function testServiceWithoutTheParameterGetsNoBindingAndCompiles(): void
    {
        $this->writeManifest('fake-vendor');

        $container = $this->container();
        $container->register(FakeCacheNonConsumer::class, FakeCacheNonConsumer::class)->setPublic(true);

        $this->pass()->process($container);

        $this->assertArrayNotHasKey(PluginCacheDirectoryInterface::class, $container->getDefinition(FakeCacheNonConsumer::class)->getBindings());
        $container->compile();
        $this->assertTrue($container->has(FakeCacheNonConsumer::class));
    }

    public function testAbstractDefinitionIsSkipped(): void
    {
        $this->writeManifest('fake-vendor');

        $container = $this->container();
        $container->register(FakeCacheConsumer::class, FakeCacheConsumer::class)->setAbstract(true);

        $this->pass()->process($container);

        $this->assertSame([], $container->getDefinition(FakeCacheConsumer::class)->getBindings());
    }

    private function boundDirectory(ContainerBuilder $container, string $consumer): Definition
    {
        $bindings = $container->getDefinition($consumer)->getBindings();
        $this->assertArrayHasKey(PluginCacheDirectoryInterface::class, $bindings);

        $reference = $bindings[PluginCacheDirectoryInterface::class]->getValues()[0];
        $this->assertInstanceOf(Reference::class, $reference);

        return $container->getDefinition((string) $reference);
    }

    private function pluginIdOf(Definition $directory): string
    {
        $idDefinition = $directory->getArgument(0);
        $this->assertInstanceOf(Definition::class, $idDefinition);

        return (string) $idDefinition->getArgument(0);
    }

    private function parameterOf(Definition $directory): mixed
    {
        return $directory->getArgument(1);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.plugin_cache_dir', '/runtime/plugin-cache');

        return $container;
    }

    private function pass(): PluginCacheDirectoryScopePass
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return new PluginCacheDirectoryScopePass($registry);
    }

    private function writeManifest(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $pluginId,
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
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
