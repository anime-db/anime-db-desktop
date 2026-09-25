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

use App\Command\PluginContractsDriftSyncCommand;
use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistrySignatureVerifier;
use App\Service\PluginContracts\Drift\DriftMarker;
use App\Service\PluginContracts\LagReason;
use App\Tests\Unit\Service\PluginContracts\Drift\Fake\FakeDriftGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginContractsDriftSyncCommandTest extends TestCase
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

    public function testLaggingPluginGetsAnIssue(): void
    {
        $gateway = new FakeDriftGateway();

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.21']));

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $gateway->executor->createdIssues);
        self::assertStringContainsString('animedb-shikimori: create', $tester->getDisplay());
    }

    public function testHealthyPluginWithOpenIssueGetsItClosed(): void
    {
        $gateway = new FakeDriftGateway(['animedb-shikimori' => I18nCoverageIssueSnapshot::open(9, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22']));

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([9], $gateway->executor->closed);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: bool}>
     */
    public static function registryLevelFailures(): iterable
    {
        yield 'unknown contracts version' => [null, false];
        yield 'unstable contracts version' => ['dev-master', false];
        yield 'bad signature' => ['0.22.0', true];
    }

    #[DataProvider('registryLevelFailures')]
    public function testRegistryLevelFailureTouchesNoIssueAtAll(?string $contractsVersion, bool $badSignature): void
    {
        $gateway = new FakeDriftGateway(['animedb-shikimori' => I18nCoverageIssueSnapshot::open(9, 'x')]);
        $signature = $badSignature ? base64_encode(str_repeat('x', \SODIUM_CRYPTO_SIGN_BYTES)) : null;

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22']), contractsVersion: $contractsVersion, signature: $signature);

        self::assertSame(2, $tester->getStatusCode());
        self::assertSame(0, $gateway->writes());
        self::assertSame([], $gateway->stateReads);
    }

    public function testUnreachableRegistryTouchesNoIssueAtAll(): void
    {
        $gateway = new FakeDriftGateway(['animedb-shikimori' => I18nCoverageIssueSnapshot::open(9, 'x')]);
        $command = new PluginContractsDriftSyncCommand(
            new PluginRegistryFetcher(new MockHttpClient(static fn (): never => throw new TransportException('down'))),
            new PluginRegistrySignatureVerifier([$this->publicKey]),
            new NullLogger(),
            $gateway,
            '0.22.0',
            '0.1.0',
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertSame(0, $gateway->writes());
    }

    public function testBrokenPinBlocksOnlyThatPlugin(): void
    {
        $gateway = new FakeDriftGateway(['animedb-good' => I18nCoverageIssueSnapshot::open(3, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-broken' => '^^broken', 'animedb-good' => '^0.22']));

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('CANNOT CHECK animedb-broken', $tester->getDisplay());
        self::assertSame([3], $gateway->executor->closed);
        self::assertNotContains('animedb-broken', $gateway->stateReads);
    }

    public function testBrokenPinDoesNotBlockSyncOfAnotherPlugin(): void
    {
        $gateway = new FakeDriftGateway(['animedb-good' => I18nCoverageIssueSnapshot::open(3, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-broken' => '^^broken', 'animedb-good' => '^0.22']), plugin: 'animedb-good');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([3], $gateway->executor->closed);
    }

    public function testEntryWithoutIdFailsExitCodeButOthersAreProcessed(): void
    {
        $gateway = new FakeDriftGateway(['animedb-good' => I18nCoverageIssueSnapshot::open(3, 'x')]);
        $json = $this->registryJson(['animedb-good' => '^0.22'], extraEntries: [['versions' => []]]);

        $tester = $this->sync($gateway, $json);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([3], $gateway->executor->closed);
    }

    public function testMoreThanOneOpenIssueMeansCannotCheckWithoutWrites(): void
    {
        $gateway = new FakeDriftGateway(['animedb-shikimori' => 'ambiguous']);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.21']));

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('CANNOT CHECK animedb-shikimori', $tester->getDisplay());
        self::assertSame(0, $gateway->writes());
    }

    public function testFailureOfOnePluginDoesNotStopTheOthers(): void
    {
        $gateway = new FakeDriftGateway(['animedb-a' => 'boom', 'animedb-b' => I18nCoverageIssueSnapshot::open(4, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-a' => '^0.21', 'animedb-b' => '^0.22']));

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([4], $gateway->executor->closed);
    }

    public function testUnknownPluginArgumentIsAnErrorAndClosesNothing(): void
    {
        $gateway = new FakeDriftGateway(['animedb-other' => I18nCoverageIssueSnapshot::open(4, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22']), plugin: 'animedb-other');

        self::assertSame(2, $tester->getStatusCode());
        self::assertSame(0, $gateway->writes());
    }

    public function testPluginDroppedFromTheRegistryKeepsItsIssue(): void
    {
        $gateway = new FakeDriftGateway(['animedb-removed' => I18nCoverageIssueSnapshot::open(4, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22']));

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, $gateway->writes());
        self::assertNotContains('animedb-removed', $gateway->stateReads);
    }

    public function testMissingTrackingLabelFailsBeforeAnyWrite(): void
    {
        $gateway = new FakeDriftGateway(['animedb-shikimori' => I18nCoverageIssueSnapshot::open(9, 'x')], labels: []);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22']));

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('plugin-contracts-drift', $tester->getDisplay());
        self::assertSame(0, $gateway->writes());
    }

    public function testMissingTrackingLabelOnlyWarnsOnDryRun(): void
    {
        $gateway = new FakeDriftGateway(labels: []);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.21']), dryRun: true);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getDisplay());
        self::assertSame(0, $gateway->writes());
    }

    public function testDryRunReadsButWritesNothing(): void
    {
        $gateway = new FakeDriftGateway(['animedb-a' => I18nCoverageIssueSnapshot::open(4, 'x')]);

        $tester = $this->sync($gateway, $this->registryJson(['animedb-a' => '^0.22', 'animedb-b' => '^0.21']), dryRun: true);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, $gateway->writes());
        self::assertSame(['animedb-a', 'animedb-b'], $gateway->stateReads);
        self::assertStringContainsString('animedb-a: close (dry run)', $tester->getDisplay());
        self::assertStringContainsString('animedb-b: create (dry run)', $tester->getDisplay());
    }

    public function testAssumedContractsVersionDrivesCreateThenTheRealOneCloses(): void
    {
        $json = $this->registryJson(['animedb-shikimori' => '^0.22']);
        $gateway = new FakeDriftGateway();

        $first = $this->sync($gateway, $json, assumed: '0.23.0');

        self::assertSame(0, $first->getStatusCode());
        self::assertCount(1, $gateway->executor->createdIssues);
        $body = $gateway->executor->createdIssues[0]['body'];
        self::assertSame('0.23.0', DriftMarker::parse($body)?->contractsVersion);

        $gateway = new FakeDriftGateway(['animedb-shikimori' => I18nCoverageIssueSnapshot::open(11, $body)]);
        $second = $this->sync($gateway, $json);

        self::assertSame(0, $second->getStatusCode());
        self::assertSame([11], $gateway->executor->closed);
    }

    public function testAssumedUnstableVersionCannotSync(): void
    {
        $gateway = new FakeDriftGateway();

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22']), assumed: 'dev-master');

        self::assertSame(2, $tester->getStatusCode());
        self::assertSame(0, $gateway->writes());
    }

    public function testNotParseablePluginIsSyncedAndIdsComeFromTheRawRegistry(): void
    {
        $gateway = new FakeDriftGateway();

        $tester = $this->sync($gateway, $this->registryJson(['animedb-shikimori' => '^0.22'], brokenManifest: true));

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $gateway->executor->createdIssues);
        self::assertSame(LagReason::NOT_PARSEABLE, DriftMarker::parse($gateway->executor->createdIssues[0]['body'])?->reason);
    }

    /**
     * @param array<string, string>      $pinsById
     * @param list<array<string, mixed>> $extraEntries
     */
    private function registryJson(array $pinsById, bool $brokenManifest = false, array $extraEntries = []): string
    {
        $plugins = [];
        foreach ($pinsById as $id => $pin) {
            $manifest = ['id' => $id, 'name' => $id, 'version' => '0.9.2', 'type' => 'integration', 'features' => ['filler' => true], 'require' => ['core' => '>=2.0.0', 'php' => '>=8.2']];
            $plugins[] = [
                'id' => $id,
                'manifest' => $brokenManifest ? ['id' => $id] : $manifest,
                'versions' => [['version' => '0.9.2', 'core' => '>=2.0.0', 'sha256' => str_repeat('a', 64), 'plugin_contracts' => $pin]],
            ];
        }

        return json_encode(['sequence' => 7, 'asset_mirrors' => [], 'plugins' => [...$plugins, ...$extraEntries]], \JSON_THROW_ON_ERROR);
    }

    private function sync(
        FakeDriftGateway $gateway,
        string $registryJson,
        ?string $plugin = null,
        bool $dryRun = false,
        ?string $assumed = null,
        ?string $contractsVersion = '0.22.0',
        ?string $signature = null,
    ): CommandTester {
        $signature ??= base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey));
        $httpClient = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(str_ends_with($url, '.sig') ? $signature : $registryJson));

        $tester = new CommandTester(new PluginContractsDriftSyncCommand(
            new PluginRegistryFetcher($httpClient),
            new PluginRegistrySignatureVerifier([$this->publicKey]),
            new NullLogger(),
            $gateway,
            $contractsVersion,
            '0.1.0',
        ));

        $input = ['--dry-run' => $dryRun];
        if ($plugin !== null) {
            $input['plugin'] = $plugin;
        }
        if ($assumed !== null) {
            $input['--assume-contracts-version'] = $assumed;
        }
        $tester->execute($input);

        return $tester;
    }
}
