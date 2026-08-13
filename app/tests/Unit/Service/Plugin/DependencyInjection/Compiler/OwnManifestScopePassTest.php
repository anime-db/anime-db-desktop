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

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\FakeVendor\FakeNonConsumer;
use AnimeDb\Plugins\FakeVendor\FakeOwnManifestConsumer;
use AnimeDb\Plugins\FakeVendorTwo\FakeOwnManifestConsumerTwo;
use App\Service\Plugin\DependencyInjection\Compiler\OwnManifestScopePass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\OwnManifest;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class OwnManifestScopePassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../../../Fixtures/Plugin/OwnManifestScopePass/FakeOwnManifestConsumer.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/OwnManifestScopePass/FakeOwnManifestConsumerTwo.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/OwnManifestScopePass/FakeNonConsumer.php';

        $this->pluginsDir = sys_get_temp_dir().'/anime-own-manifest-scope-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testBindsPluginsOwnManifestToServiceThatTypeHintsIt(): void
    {
        $this->writeManifest('fake-vendor', 'Fake Vendor', '1.2.3');

        $container = new ContainerBuilder();
        $container->register(FakeOwnManifestConsumer::class, FakeOwnManifestConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeOwnManifestConsumer::class);
        $bindings = $definition->getBindings();
        $this->assertArrayHasKey(OwnManifestInterface::class, $bindings);

        $reference = $bindings[OwnManifestInterface::class]->getValues()[0];
        $this->assertInstanceOf(Reference::class, $reference);

        $manifestDefinition = $container->getDefinition((string) $reference);
        $this->assertSame(OwnManifest::class, $manifestDefinition->getClass());
        $this->assertSame(['fake-vendor', 'Fake Vendor', '1.2.3'], $manifestDefinition->getArguments());
    }

    public function testEachPluginGetsItsOwnManifestInstanceIsolatedByNamespace(): void
    {
        $this->writeManifest('fake-vendor', 'Fake Vendor', '1.2.3');
        $this->writeManifest('fake-vendor-two', 'Fake Vendor Two', '4.5.6');

        $container = new ContainerBuilder();
        $container->register(FakeOwnManifestConsumer::class, FakeOwnManifestConsumer::class);
        $container->register(FakeOwnManifestConsumerTwo::class, FakeOwnManifestConsumerTwo::class);

        $this->pass()->process($container);

        $firstReference = $container->getDefinition(FakeOwnManifestConsumer::class)
            ->getBindings()[OwnManifestInterface::class]->getValues()[0];
        $secondReference = $container->getDefinition(FakeOwnManifestConsumerTwo::class)
            ->getBindings()[OwnManifestInterface::class]->getValues()[0];

        $this->assertInstanceOf(Reference::class, $firstReference);
        $this->assertInstanceOf(Reference::class, $secondReference);
        $this->assertNotSame((string) $firstReference, (string) $secondReference);

        $this->assertSame(
            ['fake-vendor', 'Fake Vendor', '1.2.3'],
            $container->getDefinition((string) $firstReference)->getArguments(),
        );
        $this->assertSame(
            ['fake-vendor-two', 'Fake Vendor Two', '4.5.6'],
            $container->getDefinition((string) $secondReference)->getArguments(),
        );
    }

    public function testDoesNotBindServiceThatDoesNotTypeHintOwnManifestInterface(): void
    {
        $this->writeManifest('fake-vendor', 'Fake Vendor', '1.2.3');

        $container = new ContainerBuilder();
        $container->register(FakeNonConsumer::class, FakeNonConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeNonConsumer::class);
        $this->assertArrayNotHasKey(OwnManifestInterface::class, $definition->getBindings());
    }

    public function testNoInstalledPluginsLeavesContainerUnbound(): void
    {
        $container = new ContainerBuilder();
        $container->register(FakeOwnManifestConsumer::class, FakeOwnManifestConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeOwnManifestConsumer::class);
        $this->assertArrayNotHasKey(OwnManifestInterface::class, $definition->getBindings());
    }

    private function pass(): OwnManifestScopePass
    {
        $logger = new NullLogger();
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            $logger,
        );
        $registry->reconcile();

        return new OwnManifestScopePass($registry);
    }

    private function writeManifest(string $pluginId, string $name, string $version): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => $version,
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
