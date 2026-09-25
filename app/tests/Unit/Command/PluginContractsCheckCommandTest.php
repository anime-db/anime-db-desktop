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

use App\Command\PluginContractsCheckCommand;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistrySignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginContractsCheckCommandTest extends TestCase
{
    private string $publicKey;
    /** @var non-empty-string */
    private string $secretKey;

    protected function setUp(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    public function testNoLaggingPluginsExitsZeroAndPrintsSourceAndSequence(): void
    {
        $tester = $this->runCheck('0.22.0', $this->registryJson('^0.22'));

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('https://mr01.anime-db.org/plugins-registry.json (sequence 7)', $tester->getDisplay());
    }

    public function testLaggingPluginExitsOneAndPrintsIdVersionPinAndAppVersion(): void
    {
        $tester = $this->runCheck('0.22.0', $this->registryJson('^0.21'));

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('LAGS animedb-shikimori: latest version 0.9.2 pins plugin-contracts "^0.21", app has 0.22.0', $display);
    }

    public function testBadSignatureCannotCheck(): void
    {
        $tester = $this->runCheck('0.22.0', $this->registryJson('^0.22'), signature: base64_encode(str_repeat('x', \SODIUM_CRYPTO_SIGN_BYTES)));

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('CANNOT CHECK', $tester->getDisplay());
    }

    public function testUnreachableRegistryCannotCheck(): void
    {
        $command = new PluginContractsCheckCommand(
            new PluginRegistryFetcher(new MockHttpClient(static fn (): never => throw new TransportException('down'))),
            new PluginRegistrySignatureVerifier([$this->publicKey]),
            new NullLogger(),
            '0.22.0',
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('CANNOT CHECK', $tester->getDisplay());
    }

    public function testNullAndUnstableAppVersionsCannotCheck(): void
    {
        foreach ([null, 'dev-master'] as $version) {
            $tester = $this->runCheck($version, $this->registryJson('^0.21'));

            self::assertSame(2, $tester->getStatusCode());
            self::assertStringContainsString('CANNOT CHECK', $tester->getDisplay());
        }
    }

    public function testUnparseablePinCannotCheck(): void
    {
        $tester = $this->runCheck('0.22.0', $this->registryJson('^^broken'));

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('CANNOT CHECK', $tester->getDisplay());
    }

    public function testPluginDroppedByTheParserIsReportedAsLagging(): void
    {
        $tester = $this->runCheck('0.22.0', $this->registryJson('^0.22', brokenManifest: true));

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('LAGS animedb-shikimori: the registry entry is not accepted by this app version (latest version: 0.9.2, pin: ^0.22)', $tester->getDisplay());
    }

    private function runCheck(?string $contractsVersion, string $registryJson, ?string $signature = null): CommandTester
    {
        $signature ??= base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey));
        $httpClient = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(str_ends_with($url, '.sig') ? $signature : $registryJson));

        $tester = new CommandTester(new PluginContractsCheckCommand(
            new PluginRegistryFetcher($httpClient),
            new PluginRegistrySignatureVerifier([$this->publicKey]),
            new NullLogger(),
            $contractsVersion,
        ));
        $tester->execute([]);

        return $tester;
    }

    private function registryJson(string $pin, bool $brokenManifest = false): string
    {
        $manifest = ['id' => 'animedb-shikimori', 'name' => 'Shikimori', 'version' => '0.9.2', 'type' => 'integration', 'features' => ['filler' => true], 'require' => ['core' => '>=2.0.0', 'php' => '>=8.2']];

        return json_encode([
            'sequence' => 7,
            'asset_mirrors' => [],
            'plugins' => [[
                'id' => 'animedb-shikimori',
                'manifest' => $brokenManifest ? ['id' => 'animedb-shikimori'] : $manifest,
                'versions' => [['version' => '0.9.2', 'core' => '>=2.0.0', 'sha256' => str_repeat('a', 64), 'plugin_contracts' => $pin]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }
}
