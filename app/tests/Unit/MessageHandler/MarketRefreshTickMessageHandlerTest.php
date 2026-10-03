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

use App\Message\MarketRefreshTickMessage;
use App\MessageHandler\MarketRefreshTickMessageHandler;
use App\Service\AppConfigStore;
use App\Service\Market\MarketRefreshService;
use App\Service\Market\MarketSnapshot;
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

/**
 * Every refresh triggered through {@see MarketRefreshTickMessageHandler} ends up inside
 * {@see MarketRefreshService::refresh()}, which always stamps
 * {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT} once it actually runs (success
 * or failure alike — see that constant's docblock). That marker is a deterministic, directly
 * observable stand-in for "did the handler call refresh() at all": the HTTP client below always
 * serves a validly signed registry, so every call the handler makes succeeds, and every test here
 * only has to check whether the marker moved past its baseline, never elapsed wall-clock time.
 */
final class MarketRefreshTickMessageHandlerTest extends TestCase
{
    private const string CORE_VERSION = '2.5.0';

    private string $snapshotCachePath;
    private string $configPath;
    private string $registryCachePath;
    private string $lockPath;
    private string $trustedPublicKey;

    /** @var non-empty-string */
    private string $secretKey;

    protected function setUp(): void
    {
        $prefix = sys_get_temp_dir().'/anime-market-refresh-tick-handler-test-'.uniqid();
        $this->snapshotCachePath = $prefix.'-snapshot.json';
        $this->configPath = $prefix.'-config.json';
        $this->registryCachePath = $prefix.'-registry.json';
        $this->lockPath = $prefix.'.lock';

        $keyPair = sodium_crypto_sign_keypair();
        $this->trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    protected function tearDown(): void
    {
        foreach ([$this->snapshotCachePath, $this->configPath, $this->registryCachePath, $this->lockPath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testRefreshesWhenNoSnapshotIsCached(): void
    {
        $this->writeLastRefreshAt(new \DateTimeImmutable('-1 hour'));

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    public function testRefreshesWhenTheCachedSnapshotWasBuiltForAnotherCoreVersion(): void
    {
        $this->writeSnapshot(coreVersion: '1.0.0');
        $this->writeLastRefreshAt(new \DateTimeImmutable('-1 hour'));

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    public function testRefreshesWhenLastRefreshAtIsMissing(): void
    {
        $this->writeSnapshot(coreVersion: self::CORE_VERSION);

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    public function testRefreshesWhenLastRefreshAtIsOlderThanOneDay(): void
    {
        $this->writeSnapshot(coreVersion: self::CORE_VERSION);
        $this->writeLastRefreshAt(new \DateTimeImmutable('-25 hours'));

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    public function testRefreshesWhenLastRefreshAtIsInTheFuture(): void
    {
        $this->writeSnapshot(coreVersion: self::CORE_VERSION);
        $this->writeLastRefreshAt(new \DateTimeImmutable('+1 hour'));

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    public function testDoesNotRefreshWhenTheSnapshotIsReadyAndLastRefreshAtIsRecent(): void
    {
        $this->writeSnapshot(coreVersion: self::CORE_VERSION);
        $this->writeLastRefreshAt(new \DateTimeImmutable('-1 hour'));

        $this->invokeHandler();

        $this->assertRefreshWasNotTriggered();
    }

    public function testRefreshesWhenLastRefreshAtIsNotAString(): void
    {
        $this->writeSnapshot(coreVersion: self::CORE_VERSION);
        $this->writeRawLastRefreshAt(123);

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    public function testRefreshesWhenLastRefreshAtDoesNotParseAsAnAtomDate(): void
    {
        $this->writeSnapshot(coreVersion: self::CORE_VERSION);
        $this->writeRawLastRefreshAt('garbage');

        $this->invokeHandler();

        $this->assertRefreshWasTriggered();
    }

    private function invokeHandler(): void
    {
        $registryJson = json_encode(['sequence' => 1, 'asset_mirrors' => [], 'plugins' => []], \JSON_THROW_ON_ERROR);
        $signature = base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey));

        $httpClient = new MockHttpClient(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '.sig')
                ? new MockResponse($signature)
                : new MockResponse($registryJson),
            null,
        );

        $configStore = new AppConfigStore($this->configPath);

        $handler = new MarketRefreshTickMessageHandler(
            new MarketSnapshotCache($this->snapshotCachePath),
            $configStore,
            new MarketRefreshService(
                new PluginRegistryLoader(
                    new PluginRegistryFetcher($httpClient),
                    new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
                    new PluginRegistryCache($this->registryCachePath, new NullLogger()),
                    new PluginRegistryHighWaterMarkStore($configStore),
                    new NullLogger(),
                ),
                new MarketSnapshotBuilder(),
                new MarketSnapshotCache($this->snapshotCachePath),
                $configStore,
                new NullLogger(),
                self::CORE_VERSION,
                $this->lockPath,
            ),
            self::CORE_VERSION,
        );

        $handler(new MarketRefreshTickMessage());
    }

    private function writeSnapshot(string $coreVersion): void
    {
        (new MarketSnapshotCache($this->snapshotCachePath))->store(new MarketSnapshot($coreVersion, 1, [], []));
    }

    private function writeLastRefreshAt(\DateTimeImmutable $lastRefreshAt): void
    {
        $this->writeRawLastRefreshAt($lastRefreshAt->format(\DateTimeInterface::ATOM));
    }

    private function writeRawLastRefreshAt(string|int $lastRefreshAt): void
    {
        (new AppConfigStore($this->configPath))->update(static function (array $config) use ($lastRefreshAt): array {
            $config[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT] = $lastRefreshAt;

            return $config;
        });
    }

    private function assertRefreshWasTriggered(): void
    {
        $config = (new AppConfigStore($this->configPath))->read();
        $this->assertArrayHasKey(MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT, $config);
    }

    private function assertRefreshWasNotTriggered(): void
    {
        $config = (new AppConfigStore($this->configPath))->read();
        $this->assertArrayNotHasKey(MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT, $config);
    }
}
