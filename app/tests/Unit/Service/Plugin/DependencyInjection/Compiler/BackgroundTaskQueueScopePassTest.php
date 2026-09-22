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

use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use AnimeDb\Plugins\FakeVendor\FakeBackgroundTaskQueueConsumer;
use AnimeDb\Plugins\FakeVendor\FakeBackgroundTaskQueueNonConsumer;
use AnimeDb\Plugins\FakeVendor\FakeSubtypeQueueConsumer;
use AnimeDb\Plugins\FakeVendorTwo\FakeBackgroundTaskQueueConsumerTwo;
use App\Service\Plugin\BackgroundTaskQueue;
use App\Service\Plugin\DependencyInjection\Compiler\BackgroundTaskQueueScopePass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class BackgroundTaskQueueScopePassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../../../Fixtures/Plugin/BackgroundTaskQueueScopePass/FakeBackgroundTaskQueueConsumer.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/BackgroundTaskQueueScopePass/FakeBackgroundTaskQueueConsumerTwo.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/BackgroundTaskQueueScopePass/FakeBackgroundTaskQueueNonConsumer.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/BackgroundTaskQueueScopePass/FakeQueueSubtype.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/BackgroundTaskQueueScopePass/FakeSubtypeQueueConsumer.php';

        $this->pluginsDir = sys_get_temp_dir().'/anime-background-task-queue-scope-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testBindsPluginsOwnQueueToServiceThatTypeHintsIt(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeBackgroundTaskQueueConsumer::class, FakeBackgroundTaskQueueConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeBackgroundTaskQueueConsumer::class);
        $bindings = $definition->getBindings();
        $this->assertArrayHasKey(BackgroundTaskQueueInterface::class, $bindings);

        $reference = $bindings[BackgroundTaskQueueInterface::class]->getValues()[0];
        $this->assertInstanceOf(Reference::class, $reference);

        $queueDefinition = $container->getDefinition((string) $reference);
        $this->assertSame(BackgroundTaskQueue::class, $queueDefinition->getClass());
        $this->assertSame('fake-vendor', $queueDefinition->getArguments()[0]->getArguments()[0]);
    }

    public function testEachPluginGetsItsOwnQueueInstanceIsolatedByNamespace(): void
    {
        $this->writeManifest('fake-vendor');
        $this->writeManifest('fake-vendor-two');

        $container = new ContainerBuilder();
        $container->register(FakeBackgroundTaskQueueConsumer::class, FakeBackgroundTaskQueueConsumer::class);
        $container->register(FakeBackgroundTaskQueueConsumerTwo::class, FakeBackgroundTaskQueueConsumerTwo::class);

        $this->pass()->process($container);

        $firstReference = $container->getDefinition(FakeBackgroundTaskQueueConsumer::class)
            ->getBindings()[BackgroundTaskQueueInterface::class]->getValues()[0];
        $secondReference = $container->getDefinition(FakeBackgroundTaskQueueConsumerTwo::class)
            ->getBindings()[BackgroundTaskQueueInterface::class]->getValues()[0];

        $this->assertInstanceOf(Reference::class, $firstReference);
        $this->assertInstanceOf(Reference::class, $secondReference);
        $this->assertNotSame((string) $firstReference, (string) $secondReference);

        $this->assertSame(
            'fake-vendor',
            $container->getDefinition((string) $firstReference)->getArguments()[0]->getArguments()[0],
        );
        $this->assertSame(
            'fake-vendor-two',
            $container->getDefinition((string) $secondReference)->getArguments()[0]->getArguments()[0],
        );
    }

    public function testDoesNotBindServiceThatDoesNotTypeHintBackgroundTaskQueueInterface(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeBackgroundTaskQueueNonConsumer::class, FakeBackgroundTaskQueueNonConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeBackgroundTaskQueueNonConsumer::class);
        $this->assertArrayNotHasKey(BackgroundTaskQueueInterface::class, $definition->getBindings());
    }

    /**
     * Regression coverage for the "mismatched type with a default" trap issue #702 calls out
     * explicitly: a constructor parameter typed to a *subtype* of
     * {@see BackgroundTaskQueueInterface} rather than the interface itself must never get a
     * binding — `?FakeQueueSubtype $queue = null` in the fixture below has a `null` default
     * precisely so a container compile would succeed either way, silently leaving the property
     * `null` if this pass ever started matching subtypes.
     */
    public function testDoesNotBindServiceWhoseConstructorTypeHintsASubtypeOfTheInterface(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeSubtypeQueueConsumer::class, FakeSubtypeQueueConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeSubtypeQueueConsumer::class);
        $this->assertArrayNotHasKey(BackgroundTaskQueueInterface::class, $definition->getBindings());
    }

    public function testNoInstalledPluginsLeavesContainerUnbound(): void
    {
        $container = new ContainerBuilder();
        $container->register(FakeBackgroundTaskQueueConsumer::class, FakeBackgroundTaskQueueConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeBackgroundTaskQueueConsumer::class);
        $this->assertArrayNotHasKey(BackgroundTaskQueueInterface::class, $definition->getBindings());
    }

    private function pass(): BackgroundTaskQueueScopePass
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return new BackgroundTaskQueueScopePass($registry);
    }

    private function writeManifest(string $pluginId): void
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
