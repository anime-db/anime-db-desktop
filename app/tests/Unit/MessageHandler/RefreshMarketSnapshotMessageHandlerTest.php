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

use App\Message\RefreshMarketSnapshotMessage;
use App\MessageHandler\RefreshMarketSnapshotMessageHandler;
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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshMarketSnapshotMessageHandlerTest extends TestCase
{
    private string $registryCachePath;
    private string $snapshotCachePath;
    private string $configPath;
    private string $lockPath;
    private string $trustedPublicKey;

    /** @var non-empty-string */
    private string $secretKey;

    protected function setUp(): void
    {
        $prefix = sys_get_temp_dir().'/anime-refresh-market-snapshot-handler-test-'.uniqid();
        $this->registryCachePath = $prefix.'-registry.json';
        $this->snapshotCachePath = $prefix.'-snapshot.json';
        $this->configPath = $prefix.'-config.json';
        $this->lockPath = $prefix.'.lock';

        $keyPair = sodium_crypto_sign_keypair();
        $this->trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    protected function tearDown(): void
    {
        foreach ([$this->registryCachePath, $this->configPath, $this->lockPath, $this->snapshotCachePath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testInvokingTheHandlerRunsAMarketRefresh(): void
    {
        $registryJson = json_encode(['sequence' => 1, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR);
        $signature = base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey));

        $httpClient = new MockHttpClient(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '.sig')
                ? new MockResponse($signature)
                : new MockResponse($registryJson),
            null,
        );

        $handler = new RefreshMarketSnapshotMessageHandler(new MarketRefreshService(
            new PluginRegistryLoader(
                new PluginRegistryFetcher($httpClient),
                new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
                new PluginRegistryCache($this->registryCachePath),
                new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath)),
            ),
            new MarketSnapshotBuilder(),
            new MarketSnapshotCache($this->snapshotCachePath),
            new AppConfigStore($this->configPath),
            new NullLogger(),
            '2.5.0',
            $this->lockPath,
        ));

        $handler(new RefreshMarketSnapshotMessage());

        $this->assertFileExists($this->snapshotCachePath);
    }
}
