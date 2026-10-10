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

namespace App\Tests\Unit\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\EventSubscriber\PluginCacheCleanupSubscriber;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheDirectories;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PluginCacheDirectoriesTest extends TestCase
{
    private string $rootDir;
    private string $pluginsDir;
    private string $cacheDir;
    private InstalledPluginsRegistry $registry;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-plugin-cache-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        $this->cacheDir = $this->rootDir.'/plugin-cache';
        mkdir($this->pluginsDir, recursive: true);

        $this->registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testRemoverDeletesOnlyTheRemovedPluginsCacheDirectory(): void
    {
        $this->install('plugin-one');
        $this->install('plugin-two');
        $this->cacheFile('plugin-one');
        $this->cacheFile('plugin-two');
        $this->registry->reconcile();

        (new PluginRemover($this->registry, new PluginCacheDirectories($this->cacheDir, new NullLogger())))->remove(new PluginId('plugin-one'));

        $this->assertDirectoryDoesNotExist($this->cacheDir.'/plugin-one');
        $this->assertFileExists($this->cacheDir.'/plugin-two/dump.bin');
    }

    public function testCacheRemovalFailureIsLoggedAndThePluginIsStillRemoved(): void
    {
        $this->install('plugin-one');
        $this->registry->reconcile();
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->cacheFile('plugin-one');
        // Read-only cache root: the directory can be neither renamed nor emptied.
        chmod($this->cacheDir.'/plugin-one', 0o555);
        chmod($this->cacheDir, 0o555);

        try {
            if (@file_put_contents($this->cacheDir.'/plugin-one/probe', 'x') !== false || posix_geteuid() === 0) {
                $this->markTestSkipped('Filesystem permissions are not enforced here (running as root).');
            }

            (new PluginRemover($this->registry, new PluginCacheDirectories($this->cacheDir, $logger)))->remove(new PluginId('plugin-one'));

            $this->assertFalse($this->registry->has(new PluginId('plugin-one')));
            $this->assertDirectoryDoesNotExist($this->pluginsDir.'/plugin-one');
            $this->assertNotSame([], $logger->messages);
        } finally {
            chmod($this->cacheDir, 0o755);
            @chmod($this->cacheDir.'/plugin-one', 0o755);
            @chmod($this->cacheDir.'/plugin-one.removing', 0o755);
        }
    }

    public function testRemoveOrphansDeletesDirectoriesOfUninstalledPluginsOnly(): void
    {
        $this->cacheFile('installed-plugin');
        $this->cacheFile('gone-plugin');

        (new PluginCacheDirectories($this->cacheDir, new NullLogger()))->removeOrphans([new PluginId('installed-plugin')]);

        $this->assertFileExists($this->cacheDir.'/installed-plugin/dump.bin');
        $this->assertDirectoryDoesNotExist($this->cacheDir.'/gone-plugin');
    }

    public function testRemoveOrphansToleratesAMissingRoot(): void
    {
        (new PluginCacheDirectories($this->cacheDir, new NullLogger()))->removeOrphans([]);

        $this->assertDirectoryDoesNotExist($this->cacheDir);
    }

    public function testStartupSubscriberRemovesOrphansOfPluginsThatAreNotInstalledAndRunsOnce(): void
    {
        $this->install('installed-plugin');
        $this->registry->reconcile();
        $this->cacheFile('installed-plugin');
        $this->cacheFile('gone-plugin');

        $subscriber = new PluginCacheCleanupSubscriber($this->registry, new PluginCacheDirectories($this->cacheDir, new NullLogger()));
        $subscriber->onKernelRequest($this->mainRequest());

        $this->assertFileExists($this->cacheDir.'/installed-plugin/dump.bin');
        $this->assertDirectoryDoesNotExist($this->cacheDir.'/gone-plugin');

        $this->cacheFile('gone-plugin');
        $subscriber->onKernelRequest($this->mainRequest());
        $this->assertDirectoryExists($this->cacheDir.'/gone-plugin');
    }

    private function mainRequest(): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST);
    }

    private function cacheFile(string $pluginId): void
    {
        @mkdir($this->cacheDir.'/'.$pluginId, 0o755, true);
        file_put_contents($this->cacheDir.'/'.$pluginId.'/dump.bin', 'x');
    }

    private function install(string $pluginId): void
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
        @chmod($dir, 0o755);

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
