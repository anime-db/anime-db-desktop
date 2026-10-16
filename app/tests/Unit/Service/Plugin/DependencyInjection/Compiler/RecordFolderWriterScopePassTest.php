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

use AnimeDb\PluginContracts\Folder\RecordFolderWriterInterface;
use AnimeDb\Plugins\FakeVendor\FakeRecordFolderWriterConsumer;
use AnimeDb\Plugins\FakeVendorTwo\FakeRecordFolderWriterConsumerTwo;
use App\Service\Folder\RecordFolderWriter;
use App\Service\Plugin\DependencyInjection\Compiler\RecordFolderWriterScopePass;
use App\Service\Plugin\Exception\WriterRequiresLocalPluginException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RecordFolderWriterScopePassTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../../../Fixtures/Plugin/RecordFolderWriterScopePass/FakeRecordFolderWriterConsumer.php';
        require_once __DIR__.'/../../../../../Fixtures/Plugin/RecordFolderWriterScopePass/FakeRecordFolderWriterConsumerTwo.php';

        $this->pluginsDir = sys_get_temp_dir().'/anime-record-folder-writer-scope-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testLocalPluginGetsItsOwnWriter(): void
    {
        $this->writeManifest('fake-vendor', 'local');
        $this->writeManifest('fake-vendor-two', 'local');

        $container = new ContainerBuilder();
        $container->register(FakeRecordFolderWriterConsumer::class, FakeRecordFolderWriterConsumer::class);
        $container->register(FakeRecordFolderWriterConsumerTwo::class, FakeRecordFolderWriterConsumerTwo::class);

        $this->pass()->process($container);

        $first = $container->getDefinition(FakeRecordFolderWriterConsumer::class)->getBindings()[RecordFolderWriterInterface::class]->getValues()[0];
        $second = $container->getDefinition(FakeRecordFolderWriterConsumerTwo::class)->getBindings()[RecordFolderWriterInterface::class]->getValues()[0];
        $this->assertInstanceOf(Reference::class, $first);
        $this->assertInstanceOf(Reference::class, $second);
        $this->assertNotSame((string) $first, (string) $second);

        $writer = $container->getDefinition((string) $first);
        $this->assertSame(RecordFolderWriter::class, $writer->getClass());
        $this->assertSame('fake-vendor', $writer->getArguments()[0]->getArguments()[0]);
        $this->assertSame('fake-vendor-two', $container->getDefinition((string) $second)->getArguments()[0]->getArguments()[0]);
    }

    public function testIntegrationPluginAskingForTheWriterFailsTheBuild(): void
    {
        $this->writeManifest('fake-vendor', 'integration');

        $container = new ContainerBuilder();
        $container->register(FakeRecordFolderWriterConsumer::class, FakeRecordFolderWriterConsumer::class);

        $this->expectException(WriterRequiresLocalPluginException::class);

        $this->pass()->process($container);
    }

    public function testIntegrationPluginWithoutTheWriterIsUntouched(): void
    {
        $this->writeManifest('fake-vendor-two', 'integration');
        $this->writeManifest('fake-vendor', 'local');

        $container = new ContainerBuilder();
        $container->register(FakeRecordFolderWriterConsumer::class, FakeRecordFolderWriterConsumer::class);

        $this->pass()->process($container);

        $this->assertArrayHasKey(RecordFolderWriterInterface::class, $container->getDefinition(FakeRecordFolderWriterConsumer::class)->getBindings());
    }

    private function pass(): RecordFolderWriterScopePass
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return new RecordFolderWriterScopePass($registry);
    }

    private function writeManifest(string $pluginId, string $type): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => $type,
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
