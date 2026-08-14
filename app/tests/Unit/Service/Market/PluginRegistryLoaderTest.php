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

use App\Service\Market\Exception\InvalidPluginRegistrySignatureException;
use App\Service\Market\Exception\PluginRegistryFetchException;
use App\Service\Market\Exception\PluginRegistryRollbackException;
use App\Service\Market\PluginRegistryCache;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistryLoader;
use App\Service\Market\PluginRegistrySignatureVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginRegistryLoaderTest extends TestCase
{
    private string $cachePath;
    private string $trustedPublicKey;

    /** @var non-empty-string */
    private string $secretKey;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir().'/anime-market-registry-loader-test-'.uniqid().'.json';

        $keyPair = sodium_crypto_sign_keypair();
        $this->trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    protected function tearDown(): void
    {
        foreach ([$this->cachePath, $this->cachePath.'.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAcceptsARegistryWithAValidSignatureFromAKnownKey(): void
    {
        $loader = $this->loaderServing($this->sign($this->registryJson(sequence: 1)));

        $result = $loader->load();

        $this->assertTrue($result->isFresh());
        $this->assertNull($result->error);
        $this->assertSame(1, $result->registry?->sequence);
        // The accepted registry must also be cached, becoming the anti-rollback baseline.
        $this->assertSame(1, (new PluginRegistryCache($this->cachePath))->getLastSequence());
    }

    public function testRejectsARegistrySignedByAnUntrustedKey(): void
    {
        $untrustedKeyPair = sodium_crypto_sign_keypair();
        $untrustedSignature = base64_encode(sodium_crypto_sign_detached(
            $this->registryJson(sequence: 1),
            sodium_crypto_sign_secretkey($untrustedKeyPair),
        ));

        $loader = $this->loaderServing(new PluginRegistryDocumentFixture($this->registryJson(sequence: 1), $untrustedSignature));

        $result = $loader->load();

        $this->assertNull($result->registry);
        $this->assertInstanceOf(InvalidPluginRegistrySignatureException::class, $result->error);
    }

    public function testRejectsAnUnsignedRegistryButFallsBackToTheLastValidCachedOne(): void
    {
        // First load: a validly signed registry is accepted and cached.
        $this->loaderServing($this->sign($this->registryJson(sequence: 1)))->load();

        // Second load: the mirror now serves a tampered signature.
        $untrustedKeyPair = sodium_crypto_sign_keypair();
        $badSignature = base64_encode(sodium_crypto_sign_detached(
            $this->registryJson(sequence: 2),
            sodium_crypto_sign_secretkey($untrustedKeyPair),
        ));
        $loader = $this->loaderServing(new PluginRegistryDocumentFixture($this->registryJson(sequence: 2), $badSignature));

        $result = $loader->load();

        $this->assertInstanceOf(InvalidPluginRegistrySignatureException::class, $result->error);
        // Last valid (sequence 1) registry from cache is served instead of the rejected one.
        $this->assertSame(1, $result->registry?->sequence);
    }

    public function testRejectsARegistryWithASequenceLowerThanTheCachedOne(): void
    {
        // First load establishes sequence 5 as the anti-rollback baseline.
        $this->loaderServing($this->sign($this->registryJson(sequence: 5)))->load();

        // A validly signed but older (sequence 3) registry is replayed by a mirror.
        $loader = $this->loaderServing($this->sign($this->registryJson(sequence: 3)));

        $result = $loader->load();

        $error = $result->error;
        if (!$error instanceof PluginRegistryRollbackException) {
            $this->fail('Expected a PluginRegistryRollbackException.');
        }
        $this->assertSame(3, $error->rejectedSequence);
        $this->assertSame(5, $error->lastKnownSequence);
        // The last valid (sequence 5) registry stays in use.
        $this->assertSame(5, $result->registry?->sequence);
    }

    public function testFallsBackToCacheWhenEveryMirrorIsUnreachable(): void
    {
        $this->loaderServing($this->sign($this->registryJson(sequence: 1)))->load();

        $unreachableHttpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null);
        $loader = new PluginRegistryLoader(
            new PluginRegistryFetcher($unreachableHttpClient),
            new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
            new PluginRegistryCache($this->cachePath),
        );

        $result = $loader->load();

        $this->assertInstanceOf(PluginRegistryFetchException::class, $result->error);
        $this->assertSame(1, $result->registry?->sequence);
    }

    public function testReturnsNoRegistryWhenTheFirstEverLoadIsRejectedAndNoCacheExists(): void
    {
        $untrustedKeyPair = sodium_crypto_sign_keypair();
        $badSignature = base64_encode(sodium_crypto_sign_detached(
            $this->registryJson(sequence: 1),
            sodium_crypto_sign_secretkey($untrustedKeyPair),
        ));
        $loader = $this->loaderServing(new PluginRegistryDocumentFixture($this->registryJson(sequence: 1), $badSignature));

        $result = $loader->load();

        $this->assertNull($result->registry);
        $this->assertInstanceOf(InvalidPluginRegistrySignatureException::class, $result->error);
    }

    private function loaderServing(PluginRegistryDocumentFixture $document): PluginRegistryLoader
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '.sig')
                ? new MockResponse($document->signatureBase64)
                : new MockResponse($document->registryJson),
            null,
        );

        return new PluginRegistryLoader(
            new PluginRegistryFetcher($httpClient),
            new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
            new PluginRegistryCache($this->cachePath),
        );
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

/**
 * Test-only pair of registry bytes + signature, kept separate from the production
 * {@see \App\Service\Market\PluginRegistryDocument} so this file stays self-contained.
 */
final class PluginRegistryDocumentFixture
{
    public function __construct(
        public readonly string $registryJson,
        public readonly string $signatureBase64,
    ) {
    }
}
