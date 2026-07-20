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

namespace App\Tests\Unit\Service\Plugin\DependencyInjection\Compiler;

use AnimeDb\Plugins\FakeVendor\FakeFiller;
use AnimeDb\Plugins\FakeVendor\FakeSync;
use App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Fixtures\Plugin\TagPluginServicesPass\NonPluginFiller;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TagPluginServicesPassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeFiller.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeSync.php';

        $this->pluginsDir = sys_get_temp_dir().'/anime-tag-plugin-services-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testTagsFillerServiceWithFillerAndSearchByPluginTags(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeFiller::class, FakeFiller::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeFiller::class);
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.filler'));
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.search_by_plugin'));
        $this->assertSame([], $definition->getTag('app.sync'));
        $this->assertSame([], $definition->getTag('app.entry_widget'));
        $this->assertSame([], $definition->getTag('app.catalog_widget'));
    }

    public function testTagsSyncServiceWithSyncFillerAndSearchByPluginTags(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeSync::class, FakeSync::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeSync::class);
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.sync'));
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.filler'));
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.search_by_plugin'));
    }

    public function testDoesNotTagServiceOutsidePluginNamespace(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(NonPluginFiller::class, NonPluginFiller::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(NonPluginFiller::class);
        $this->assertSame([], $definition->getTag('app.filler'));
        $this->assertSame([], $definition->getTag('app.search_by_plugin'));
    }

    public function testNoInstalledPluginsLeavesContainerUntagged(): void
    {
        $container = new ContainerBuilder();
        $container->register(FakeFiller::class, FakeFiller::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeFiller::class);
        $this->assertSame([], $definition->getTag('app.filler'));
        $this->assertSame([], $definition->getTag('app.search_by_plugin'));
    }

    private function pass(): TagPluginServicesPass
    {
        $logger = new NullLogger();
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            $logger,
        );
        $registry->reconcile();

        return new TagPluginServicesPass($registry);
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
            'features' => ['filler' => true, 'sync' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
