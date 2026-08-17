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

namespace App\Tests\Unit\Command;

use App\Command\MarketRefreshCommand;
use App\Service\AppConfigStore;
use App\Service\Market\MarketRefreshService;
use App\Service\Market\MarketSnapshotBuilder;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\PluginRegistryCache;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistryHighWaterMarkStore;
use App\Service\Market\PluginRegistryLoader;
use App\Service\Market\PluginRegistrySignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Now that `app:market:refresh`'s actual logic lives in {@see MarketRefreshService} (issue #440,
 * see {@see \App\Tests\Unit\Service\Market\MarketRefreshServiceTest} for that), this only checks
 * the command maps the service's boolean result onto the right exit code.
 */
final class MarketRefreshCommandTest extends TestCase
{
    private string $registryCachePath;
    private string $snapshotCachePath;
    private string $configPath;
    private string $lockPath;

    protected function setUp(): void
    {
        $prefix = sys_get_temp_dir().'/anime-market-refresh-command-test-'.uniqid();
        $this->registryCachePath = $prefix.'-registry.json';
        $this->snapshotCachePath = $prefix.'-snapshot.json';
        $this->configPath = $prefix.'-config.json';
        $this->lockPath = $prefix.'.lock';
    }

    protected function tearDown(): void
    {
        foreach ([$this->registryCachePath, $this->configPath, $this->lockPath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testMapsAFailedRefreshToCommandFailure(): void
    {
        $unreachableHttpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null);

        $tester = new CommandTester(new MarketRefreshCommand(new MarketRefreshService(
            new PluginRegistryLoader(
                new PluginRegistryFetcher($unreachableHttpClient),
                new PluginRegistrySignatureVerifier([]),
                new PluginRegistryCache($this->registryCachePath),
                new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath)),
            ),
            new MarketSnapshotBuilder(),
            new MarketSnapshotCache($this->snapshotCachePath),
            new AppConfigStore($this->configPath),
            new NullLogger(),
            '2.5.0',
            $this->lockPath,
        )));

        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /**
     * A refresh already in flight (flock held by another process) is not a failure — the service
     * reports success and this command must not treat that as an error.
     */
    public function testMapsAnAlreadyInFlightRefreshToCommandSuccess(): void
    {
        $lockHandle = fopen($this->lockPath, 'c');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX | \LOCK_NB));

        try {
            $tester = new CommandTester(new MarketRefreshCommand(new MarketRefreshService(
                new PluginRegistryLoader(
                    new PluginRegistryFetcher(new MockHttpClient()),
                    new PluginRegistrySignatureVerifier([]),
                    new PluginRegistryCache($this->registryCachePath),
                    new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath)),
                ),
                new MarketSnapshotBuilder(),
                new MarketSnapshotCache($this->snapshotCachePath),
                new AppConfigStore($this->configPath),
                new NullLogger(),
                '2.5.0',
                $this->lockPath,
            )));

            $tester->execute([]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }
}
