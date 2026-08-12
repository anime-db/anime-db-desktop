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

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginLoader;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PluginLoaderTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-loader-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testIntegrationBundlesInstantiatesEnabledIntegrationPlugin(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $bundles = $this->loader()->integrationBundles();

        $this->assertCount(1, $bundles);
        $this->assertSame($this->bundleClass($pluginId), $bundles[0]::class);
    }

    public function testIntegrationBundlesSkipsDisabledPlugin(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $configPath = $this->pluginsDir.'/plugins.json';
        file_put_contents($configPath, json_encode([$pluginId => ['enabled' => false]]));

        $loader = $this->loader(new PluginsConfigStore($configPath));

        $this->assertSame([], $loader->integrationBundles());
    }

    public function testIntegrationBundlesSkipsAndLogsWhenSrcDirectoryMissing(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('src/'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === $pluginId),
        );

        $this->assertSame([], $this->loader(logger: $logger)->integrationBundles());
    }

    public function testIntegrationBundlesLoadsPluginWithoutBundleClassWithoutErrorLog(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeFillerClassOnly($pluginId);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->assertSame([], $this->loader(logger: $logger)->integrationBundles());
    }

    public function testRegisterAutoloadForIntegrationPluginsMakesFillerLoadableWithoutBundleClass(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeFillerClassOnly($pluginId);

        $this->loader()->registerAutoloadForIntegrationPlugins();

        $this->assertTrue(class_exists($this->fillerClass($pluginId)));
    }

    public function testIntegrationBundlesSkipsAndLogsWhenClassDoesNotImplementBundleInterface(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeNonBundleClass($pluginId);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('does not implement BundleInterface'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === $pluginId),
        );

        $this->assertSame([], $this->loader(logger: $logger)->integrationBundles());
    }

    public function testRegisterAutoloadForIntegrationPluginsMakesBundleClassLoadableWithoutInstantiatingIt(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $this->loader()->registerAutoloadForIntegrationPlugins();

        $this->assertTrue(class_exists($this->bundleClass($pluginId)));
    }

    public function testIntegrationBundlesIgnoresTranslationPlugins(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeTranslationManifest($pluginId);
        mkdir($this->pluginsDir.'/'.$pluginId.'/translations', recursive: true);

        $this->assertSame([], $this->loader()->integrationBundles());
    }

    public function testTwigPathsMapsTemplatesDirectoryToStudlyNamespace(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);
        mkdir($this->pluginsDir.'/'.$pluginId.'/templates', recursive: true);

        $paths = $this->loader()->twigPaths();

        $this->assertSame(
            [$this->pluginsDir.'/'.$pluginId.'/templates' => $this->studlyId($pluginId)],
            $paths,
        );
    }

    public function testTwigPathsOmitsPluginWithoutTemplatesDirectory(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $this->assertSame([], $this->loader()->twigPaths());
    }

    public function testIntegrationPluginServicesMapsNamespacePrefixToSourceDirectory(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $paths = $this->loader()->integrationPluginServices();

        $this->assertSame(
            ['AnimeDb\\Plugins\\'.$this->studlyId($pluginId).'\\' => $this->pluginsDir.'/'.$pluginId.'/src'],
            $paths,
        );
    }

    public function testIntegrationPluginServicesOmitsPluginWithoutSrcDirectory(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);

        $this->assertSame([], $this->loader()->integrationPluginServices());
    }

    public function testIntegrationPluginServicesIgnoresTranslationPlugins(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeTranslationManifest($pluginId);
        mkdir($this->pluginsDir.'/'.$pluginId.'/translations', recursive: true);
        mkdir($this->pluginsDir.'/'.$pluginId.'/src', recursive: true);

        $this->assertSame([], $this->loader()->integrationPluginServices());
    }

    public function testRoutingFilesReturnsExistingPluginRoutingYaml(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);
        file_put_contents($this->pluginsDir.'/'.$pluginId.'/plugin-routing.yaml', "acme:\n    resource: ~\n");

        $this->assertSame(
            [$this->pluginsDir.'/'.$pluginId.'/plugin-routing.yaml'],
            $this->loader()->routingFiles(),
        );
    }

    public function testRoutingFilesOmitsPluginWithoutRoutingFile(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $this->assertSame([], $this->loader()->routingFiles());
    }

    public function testTranslationPathsReturnsDirectoryForTranslationPlugin(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeTranslationManifest($pluginId);
        $translationsDir = $this->pluginsDir.'/'.$pluginId.'/translations';
        mkdir($translationsDir, recursive: true);
        file_put_contents($translationsDir.'/messages.fr.yaml', 'title: Titre');

        $this->assertSame([$translationsDir], $this->loader()->translationPaths());
    }

    public function testTranslationPathsSkipsAndLogsWhenDirectoryMissing(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeTranslationManifest($pluginId);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('translations/'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === $pluginId),
        );

        $this->assertSame([], $this->loader(logger: $logger)->translationPaths());
    }

    public function testTranslationPathsReturnsDirectoryForIntegrationPlugin(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);
        $translationsDir = $this->pluginsDir.'/'.$pluginId.'/translations';
        mkdir($translationsDir, recursive: true);
        file_put_contents($translationsDir.'/'.$pluginId.'.fr.yaml', 'title: Titre');

        $this->assertSame([$translationsDir], $this->loader()->translationPaths());
    }

    public function testTranslationPathsOmitsIntegrationPluginWithoutTranslationsDirectoryWithoutError(): void
    {
        $pluginId = 'acme-'.uniqid();
        $this->writeIntegrationManifest($pluginId);
        $this->writeBundleClass($pluginId);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->assertSame([], $this->loader(logger: $logger)->translationPaths());
    }

    private function loader(?PluginsConfigStore $configStore = null, ?LoggerInterface $logger = null): PluginLoader
    {
        $logger ??= new NullLogger();
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            $configStore ?? new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            $logger,
        );
        $registry->reconcile();

        return new PluginLoader($registry, $logger);
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

    private function writeBundleClass(string $pluginId): void
    {
        $srcDir = $this->pluginsDir.'/'.$pluginId.'/src';
        mkdir($srcDir, recursive: true);

        $studly = $this->studlyId($pluginId);
        file_put_contents(
            $srcDir.'/'.$studly.'Bundle.php',
            '<?php declare(strict_types=1); namespace AnimeDb\Plugins\\'.$studly.';'
            .' final class '.$studly.'Bundle extends \Symfony\Component\HttpKernel\Bundle\Bundle {}',
        );
    }

    private function writeFillerClassOnly(string $pluginId): void
    {
        $srcDir = $this->pluginsDir.'/'.$pluginId.'/src';
        mkdir($srcDir, recursive: true);

        $studly = $this->studlyId($pluginId);
        file_put_contents(
            $srcDir.'/'.$studly.'Filler.php',
            '<?php declare(strict_types=1); namespace AnimeDb\Plugins\\'.$studly.'; final class '.$studly.'Filler {}',
        );
    }

    private function writeNonBundleClass(string $pluginId): void
    {
        $srcDir = $this->pluginsDir.'/'.$pluginId.'/src';
        mkdir($srcDir, recursive: true);

        $studly = $this->studlyId($pluginId);
        file_put_contents(
            $srcDir.'/'.$studly.'Bundle.php',
            '<?php declare(strict_types=1); namespace AnimeDb\Plugins\\'.$studly.'; final class '.$studly.'Bundle {}',
        );
    }

    private function bundleClass(string $pluginId): string
    {
        $studly = $this->studlyId($pluginId);

        return 'AnimeDb\\Plugins\\'.$studly.'\\'.$studly.'Bundle';
    }

    private function fillerClass(string $pluginId): string
    {
        $studly = $this->studlyId($pluginId);

        return 'AnimeDb\\Plugins\\'.$studly.'\\'.$studly.'Filler';
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
