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

use AnimeDb\Plugins\FakeVendor\FakeBrokenMetadataEntryWidget;
use AnimeDb\Plugins\FakeVendor\FakeCatalogWidget;
use AnimeDb\Plugins\FakeVendor\FakeDuplicateNameCatalogWidget;
use AnimeDb\Plugins\FakeVendor\FakeEntryWidget;
use AnimeDb\Plugins\FakeVendor\FakeFiller;
use AnimeDb\Plugins\FakeVendor\FakeReservedNameEntryWidget;
use AnimeDb\Plugins\FakeVendor\FakeSecondSettingsPage;
use AnimeDb\Plugins\FakeVendor\FakeSettingsPage;
use AnimeDb\Plugins\FakeVendor\FakeSync;
use App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass;
use App\Service\Plugin\Exception\DuplicateWidgetNameException;
use App\Service\Plugin\Exception\MultipleSettingsPagesException;
use App\Service\Plugin\Exception\ReservedWidgetNameException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Fixtures\Plugin\TagPluginServicesPass\NonPluginFiller;
use App\Tests\Fixtures\Plugin\TagPluginServicesPass\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TagPluginServicesPassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeFiller.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeSync.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeSettingsPage.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeSecondSettingsPage.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeEntryWidget.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeCatalogWidget.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeDuplicateNameCatalogWidget.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeReservedNameEntryWidget.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/FakeBrokenMetadataEntryWidget.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/TagPluginServicesPass/RecordingLogger.php';

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

    public function testTagsSettingsPageServiceWithSettingsPageTag(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeSettingsPage::class, FakeSettingsPage::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeSettingsPage::class);
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.settings_page'));
    }

    public function testTagsEntryWidgetServiceWithCompoundPluginAndWidgetNameId(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeEntryWidget::class, FakeEntryWidget::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeEntryWidget::class);
        $this->assertSame([['id' => 'fake-vendor:related']], $definition->getTag('app.entry_widget'));
    }

    public function testTagsCatalogWidgetServiceWithCompoundPluginAndWidgetNameId(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeCatalogWidget::class, FakeCatalogWidget::class);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeCatalogWidget::class);
        $this->assertSame([['id' => 'fake-vendor:new_releases']], $definition->getTag('app.catalog_widget'));
    }

    public function testSkipsAndLogsAWidgetServiceWithABrokenMetadata(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeBrokenMetadataEntryWidget::class, FakeBrokenMetadataEntryWidget::class);

        $logger = new RecordingLogger();
        $this->pass($logger)->process($container);

        $definition = $container->getDefinition(FakeBrokenMetadataEntryWidget::class);
        $this->assertSame([], $definition->getTag('app.entry_widget'));
        $this->assertNotEmpty($logger->records);
    }

    public function testThrowsWhenAPluginRegistersTwoWidgetsWithTheSameNameAcrossPlacements(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeEntryWidget::class, FakeEntryWidget::class);
        $container->register(FakeDuplicateNameCatalogWidget::class, FakeDuplicateNameCatalogWidget::class);

        $this->expectException(DuplicateWidgetNameException::class);
        $this->pass()->process($container);
    }

    public function testThrowsWhenAWidgetNameCollidesWithAReservedFeaturesKey(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeReservedNameEntryWidget::class, FakeReservedNameEntryWidget::class);

        $this->expectException(ReservedWidgetNameException::class);
        $this->pass()->process($container);
    }

    public function testThrowsWhenAPluginRegistersMoreThanOneSettingsPageService(): void
    {
        $this->writeManifest('fake-vendor');

        $container = new ContainerBuilder();
        $container->register(FakeSettingsPage::class, FakeSettingsPage::class);
        $container->register(FakeSecondSettingsPage::class, FakeSecondSettingsPage::class);

        $this->expectException(MultipleSettingsPagesException::class);
        $this->pass()->process($container);
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

    public function testDoesNotAutoloadNonPluginServiceWithMissingDependency(): void
    {
        $this->writeManifest('fake-vendor');
        $brokenClass = $this->registerBrokenNonPluginServiceAutoloader();

        $container = new ContainerBuilder();
        $container->register(FakeFiller::class, FakeFiller::class);
        $container->register($brokenClass, $brokenClass);

        $this->pass()->process($container);

        $definition = $container->getDefinition(FakeFiller::class);
        $this->assertSame([['id' => 'fake-vendor']], $definition->getTag('app.filler'));
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

    private function pass(?LoggerInterface $logger = null): TagPluginServicesPass
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return new TagPluginServicesPass($registry, $logger ?? new NullLogger());
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

    /**
     * Writes a class outside any plugin namespace that `extends` a parent class which does not
     * exist anywhere, and registers an autoloader for it, so that autoloading it (e.g. via
     * `class_exists()`) fatals with a "Class not found" error — the same shape as the real
     * `doctrine.orm.validator.unique` landmine from issue #287 (its class extends
     * `Symfony\Component\Validator\ConstraintValidator`, from a package this app does not
     * require). Generated at runtime into a temp file rather than a checked-in fixture so static
     * analysis never has to resolve the intentionally-missing parent class.
     */
    private function registerBrokenNonPluginServiceAutoloader(): string
    {
        $class = 'App\\Tests\\Fixtures\\Plugin\\TagPluginServicesPass\\BrokenNonPluginService';
        $path = $this->pluginsDir.'/BrokenNonPluginService.php';
        file_put_contents($path, '<?php declare(strict_types=1);'
            .' namespace App\Tests\Fixtures\Plugin\TagPluginServicesPass;'
            .' final class BrokenNonPluginService'
            .' extends \App\Tests\Fixtures\Plugin\TagPluginServicesPass\MissingParentClassThatDoesNotExist {}');

        spl_autoload_register(static function (string $requested) use ($class, $path): void {
            if ($requested === $class) {
                require $path;
            }
        });

        return $class;
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
