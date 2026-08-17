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

namespace App\Tests\Unit\Service\Market;

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
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketRefreshServiceTest extends TestCase
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
        $prefix = sys_get_temp_dir().'/anime-market-refresh-service-test-'.uniqid();
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
        foreach ([
            $this->registryCachePath, $this->registryCachePath.'.tmp',
            $this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock',
            $this->lockPath,
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->snapshotCachePath.'.*.tmp') ?: [] as $file) {
            unlink($file);
        }
        if (is_file($this->snapshotCachePath)) {
            unlink($this->snapshotCachePath);
        }
    }

    public function testHappyPathBuildsAndStoresTheSnapshotRaisesHighWaterMarkAndRecordsTheRefreshTimestamp(): void
    {
        $result = $this->serviceServing($this->sign($this->registryJson(sequence: 1)))->refresh();

        $this->assertTrue($result);

        $snapshot = (new MarketSnapshotCache($this->snapshotCachePath))->load();
        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot->sequence);
        $this->assertSame('2.5.0', $snapshot->coreVersion);

        $config = (new AppConfigStore($this->configPath))->read();
        $this->assertSame(1, $config['marketRegistryHighWaterMarkSequence']);
        $this->assertIsString($config['marketLastRefreshAt']);
        $this->assertIsString($config[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT]);
    }

    /**
     * A registry that stays unreachable must still stamp the attempt marker — {@see MarketController}
     * throttles its render-fallback dispatch off this, and a marker that only ever moved on success
     * would never get set at all while the registry is down, defeating that throttle entirely
     * (issue #446 review).
     */
    public function testFailurePathLeavesNoSnapshotWhenNoRegistryHasEverBeenAcceptedButStampsTheAttemptMarker(): void
    {
        $unreachableHttpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null);
        $service = new MarketRefreshService(
            new PluginRegistryLoader(
                new PluginRegistryFetcher($unreachableHttpClient),
                new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
                new PluginRegistryCache($this->registryCachePath),
                $this->highWaterMarkStore(),
            ),
            new MarketSnapshotBuilder(),
            new MarketSnapshotCache($this->snapshotCachePath),
            new AppConfigStore($this->configPath),
            new NullLogger(),
            '2.5.0',
            $this->lockPath,
        );

        $result = $service->refresh();

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->snapshotCachePath);

        $config = (new AppConfigStore($this->configPath))->read();
        $this->assertIsString($config[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT]);
        $this->assertArrayNotHasKey('marketLastRefreshAt', $config);
    }

    public function testFailurePathDoesNotDisturbAnExistingSnapshotWhenAFollowUpRefreshIsServedFromCache(): void
    {
        // First refresh: a validly signed registry is accepted, snapshot written.
        $firstResult = $this->serviceServing($this->sign($this->registryJson(sequence: 1)))->refresh();
        $this->assertTrue($firstResult);
        $snapshotAfterFirstRun = file_get_contents($this->snapshotCachePath);

        // Second refresh: the mirror now serves a tampered signature, so the registry falls back
        // to the cached (still sequence 1) one, but PluginRegistryLoadResult::isFresh() is false.
        $untrustedKeyPair = sodium_crypto_sign_keypair();
        $badSignature = base64_encode(sodium_crypto_sign_detached(
            $this->registryJson(sequence: 2),
            sodium_crypto_sign_secretkey($untrustedKeyPair),
        ));
        $secondResult = $this->serviceServing(
            new PluginRegistryDocumentFixture($this->registryJson(sequence: 2), $badSignature),
        )->refresh();

        $this->assertFalse($secondResult);
        // The snapshot written by the first, successful refresh must be untouched.
        $this->assertSame($snapshotAfterFirstRun, file_get_contents($this->snapshotCachePath));
    }

    public function testConcurrentInvocationIsANoOpAndLeavesTheSnapshotUntouched(): void
    {
        $lockHandle = fopen($this->lockPath, 'c');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX | \LOCK_NB));

        try {
            $result = $this->serviceServing($this->sign($this->registryJson(sequence: 1)))->refresh();

            $this->assertTrue($result);
            $this->assertFileDoesNotExist($this->snapshotCachePath);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function serviceServing(PluginRegistryDocumentFixture $document): MarketRefreshService
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '.sig')
                ? new MockResponse($document->signatureBase64)
                : new MockResponse($document->registryJson),
            null,
        );

        return new MarketRefreshService(
            new PluginRegistryLoader(
                new PluginRegistryFetcher($httpClient),
                new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
                new PluginRegistryCache($this->registryCachePath),
                $this->highWaterMarkStore(),
            ),
            new MarketSnapshotBuilder(),
            new MarketSnapshotCache($this->snapshotCachePath),
            new AppConfigStore($this->configPath),
            new NullLogger(),
            '2.5.0',
            $this->lockPath,
        );
    }

    private function highWaterMarkStore(): PluginRegistryHighWaterMarkStore
    {
        return new PluginRegistryHighWaterMarkStore(new AppConfigStore($this->configPath));
    }

    private function sign(string $registryJson): PluginRegistryDocumentFixture
    {
        return new PluginRegistryDocumentFixture(
            $registryJson,
            base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey)),
        );
    }

    private function registryJson(int $sequence): string
    {
        return json_encode(['sequence' => $sequence, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR);
    }
}
