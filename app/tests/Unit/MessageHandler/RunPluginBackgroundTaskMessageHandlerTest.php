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

namespace App\Tests\Unit\MessageHandler;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface;
use App\Message\RunPluginBackgroundTaskMessage;
use App\MessageHandler\RunPluginBackgroundTaskMessageHandler;
use App\Service\Plugin\BackgroundTaskHandlerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RunPluginBackgroundTaskMessageHandlerTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-run-plugin-background-task-handler-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testCallsHandleOnTheRegisteredHandlerForThePluginId(): void
    {
        $this->writeManifest('fake-vendor');
        $task = new BackgroundTask('rescan');

        $handler = $this->createMock(BackgroundTaskHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->with($task);

        $messageHandler = new RunPluginBackgroundTaskMessageHandler(
            new BackgroundTaskHandlerRegistry(['fake-vendor' => $handler], $this->installedPluginsRegistry()),
            new NullLogger(),
        );
        $messageHandler(new RunPluginBackgroundTaskMessage('fake-vendor', $task));
    }

    /**
     * The plugin that queued the task may be gone by the time this runs (removed, disabled, or
     * never shipped a handler) — this must never throw, since `failure_transport` is deliberately
     * unconfigured (messenger.yaml) and nothing would ever pick a thrown message back up.
     */
    public function testLogsAndDropsTheTaskWhenNoHandlerIsRegisteredForThePluginId(): void
    {
        $task = new BackgroundTask('rescan');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('Discarding'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'ghost-vendor' && $context['taskName'] === 'rescan'),
        );

        $messageHandler = new RunPluginBackgroundTaskMessageHandler(
            new BackgroundTaskHandlerRegistry([], $this->installedPluginsRegistry()),
            $logger,
        );
        $messageHandler(new RunPluginBackgroundTaskMessage('ghost-vendor', $task));
    }

    private function installedPluginsRegistry(): InstalledPluginsRegistry
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return $registry;
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
