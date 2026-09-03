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

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\Plugins\FakeVendor\FakeCatalogReaderConsumer;
use AnimeDb\Plugins\FakeVendor\FakeCatalogReaderNonConsumer;
use AnimeDb\Plugins\FakeVendor\FakeEntryWidgetConsumer;
use AnimeDb\Plugins\FakeVendor\FakeSearchByPlugin;
use AnimeDb\Plugins\FakeVendorTwo\FakeCatalogReaderConsumerTwo;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogReader;
use App\Service\Plugin\DependencyInjection\Compiler\CatalogReaderScopePass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CatalogReaderScopePassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../../../Fixtures/Plugin/CatalogReaderScopePass/FakeCatalogReaderConsumer.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/CatalogReaderScopePass/FakeCatalogReaderConsumerTwo.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/CatalogReaderScopePass/FakeCatalogReaderNonConsumer.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/CatalogReaderScopePass/FakeSearchByPlugin.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/CatalogReaderScopePass/FakeEntryWidgetConsumer.php';

        $this->pluginsDir = sys_get_temp_dir().'/anime-catalog-reader-scope-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testBindsCatalogReaderToServiceThatTypeHintsIt(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeCatalogReaderConsumer::class, FakeCatalogReaderConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeCatalogReaderConsumer::class);
        $bindings = $definition->getBindings();
        self::assertArrayHasKey(CatalogReaderInterface::class, $bindings);

        $reference = $bindings[CatalogReaderInterface::class]->getValues()[0];
        self::assertInstanceOf(Reference::class, $reference);

        $readerDefinition = $container->getDefinition((string) $reference);
        self::assertSame(CatalogReader::class, $readerDefinition->getClass());

        $arguments = $readerDefinition->getArguments();
        self::assertInstanceOf(Definition::class, $arguments[0]);
        self::assertSame(PluginId::class, $arguments[0]->getClass());
        self::assertSame(['fake-vendor'], $arguments[0]->getArguments());
    }

    public function testEachPluginGetsItsOwnCatalogReaderInstanceIsolatedByNamespace(): void
    {
        $this->writeManifest('fake-vendor');
        $this->writeManifest('fake-vendor-two');

        $container = new ContainerBuilder();
        $container->register(FakeCatalogReaderConsumer::class, FakeCatalogReaderConsumer::class);
        $container->register(FakeCatalogReaderConsumerTwo::class, FakeCatalogReaderConsumerTwo::class);

        $this->pass()->process($container);

        $firstReference = $container->getDefinition(FakeCatalogReaderConsumer::class)
            ->getBindings()[CatalogReaderInterface::class]->getValues()[0];
        $secondReference = $container->getDefinition(FakeCatalogReaderConsumerTwo::class)
            ->getBindings()[CatalogReaderInterface::class]->getValues()[0];

        self::assertInstanceOf(Reference::class, $firstReference);
        self::assertInstanceOf(Reference::class, $secondReference);
        self::assertNotSame((string) $firstReference, (string) $secondReference);

        self::assertSame(
            ['fake-vendor'],
            $container->getDefinition((string) $firstReference)->getArguments()[0]->getArguments(),
        );
        self::assertSame(
            ['fake-vendor-two'],
            $container->getDefinition((string) $secondReference)->getArguments()[0]->getArguments(),
        );
    }

    public function testDoesNotBindServiceThatDoesNotTypeHintCatalogReaderInterface(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeCatalogReaderNonConsumer::class, FakeCatalogReaderNonConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeCatalogReaderNonConsumer::class);
        self::assertArrayNotHasKey(CatalogReaderInterface::class, $definition->getBindings());
    }

    public function testNoInstalledPluginsLeavesContainerUnbound(): void
    {
        $container = new ContainerBuilder();
        $container->register(FakeCatalogReaderConsumer::class, FakeCatalogReaderConsumer::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeCatalogReaderConsumer::class);
        self::assertArrayNotHasKey(CatalogReaderInterface::class, $definition->getBindings());
    }

    public function testBindsPluginsOwnSearchByPluginTaggedServiceAsExternalIdResolver(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeCatalogReaderConsumer::class, FakeCatalogReaderConsumer::class);
        $container->register(FakeSearchByPlugin::class, FakeSearchByPlugin::class)
            ->addTag('app.search_by_plugin', ['id' => 'fake-vendor']);

        $this->pass()->process($container);

        $reference = $container->getDefinition(FakeCatalogReaderConsumer::class)
            ->getBindings()[CatalogReaderInterface::class]->getValues()[0];
        $readerDefinition = $container->getDefinition((string) $reference);

        $resolverArgument = $readerDefinition->getArguments()[2];
        self::assertInstanceOf(Reference::class, $resolverArgument);
        self::assertSame(FakeSearchByPlugin::class, (string) $resolverArgument);
    }

    public function testLeavesResolverNullWhenPluginHasNoFillerSyncOrSearchByPluginService(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeCatalogReaderConsumer::class, FakeCatalogReaderConsumer::class);

        $this->pass()->process($container);

        $reference = $container->getDefinition(FakeCatalogReaderConsumer::class)
            ->getBindings()[CatalogReaderInterface::class]->getValues()[0];
        $readerDefinition = $container->getDefinition((string) $reference);

        self::assertNull($readerDefinition->getArguments()[2]);
    }

    /**
     * A widget consuming CatalogReaderInterface is itself an ExternalIdResolutionInterface
     * implementor (EntryWidgetInterface extends it), but TagPluginServicesPass only ever tags it
     * `app.entry_widget`/`app.catalog_widget`, never one of the three resolver tags — so it can
     * never be picked as its own plugin's resolver, which would otherwise be a circular service
     * reference (the widget would depend on the CatalogReader injected into it, which would
     * depend on the widget).
     */
    public function testWidgetConsumingCatalogReaderIsNeverPickedAsItsOwnPluginsResolver(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeEntryWidgetConsumer::class, FakeEntryWidgetConsumer::class)
            ->addTag('app.entry_widget', ['id' => 'fake-vendor:fake-widget']);

        $this->pass()->process($container);

        $reference = $container->getDefinition(FakeEntryWidgetConsumer::class)
            ->getBindings()[CatalogReaderInterface::class]->getValues()[0];
        $readerDefinition = $container->getDefinition((string) $reference);

        self::assertNull($readerDefinition->getArguments()[2]);
    }

    private function pass(): CatalogReaderScopePass
    {
        $logger = new NullLogger();
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            $logger,
        );
        $registry->reconcile();

        return new CatalogReaderScopePass($registry);
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
            'features' => ['widget' => true],
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
