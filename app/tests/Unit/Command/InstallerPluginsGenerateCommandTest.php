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

use App\Command\InstallerPluginsGenerateCommand;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistrySignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InstallerPluginsGenerateCommandTest extends TestCase
{
    private string $workDir;
    private string $outputPath;
    private string $publicKey;

    /** @var non-empty-string */
    private string $secretKey;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/anime-installer-plugins-test-'.uniqid();
        mkdir($this->workDir.'/app/var', recursive: true);
        $this->outputPath = $this->workDir.'/build/installer-plugins.nsh';

        $keyPair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testListsOnlyCompatibleOfficialPluginsWithTypeBasedDefaultsAndNoVersions(): void
    {
        $tester = $this->generate($this->registry([
            $this->plugin('animedb-local', 'Local files', 'local', '>=2.0.0'),
            $this->plugin('animedb-german', 'German', 'translation', '>=2.0.0'),
            $this->plugin('animedb-shikimori', 'Shikimori', 'integration', '>=2.0.0'),
            $this->plugin('animedb-future', 'Future', 'integration', '>=99.0.0'),
            $this->plugin('thirdparty-tool', 'Third party', 'local', '>=2.0.0'),
        ]));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $nsh = $this->generated();
        $this->assertStringContainsString('"Local files"', $nsh);
        $this->assertStringContainsString('"German"', $nsh);
        $this->assertStringContainsString('"Shikimori"', $nsh);
        $this->assertStringContainsString('$\"animedb-local$\"', $nsh);
        $this->assertStringContainsString('$\"animedb-german$\"', $nsh);
        $this->assertStringContainsString('$\"animedb-shikimori$\"', $nsh);
        $this->assertStringNotContainsString('Future', $nsh);
        $this->assertStringNotContainsString('animedb-future', $nsh);
        $this->assertStringNotContainsString('Third party', $nsh);
        $this->assertStringNotContainsString('thirdparty-tool', $nsh);
        $this->assertStringContainsString('!define ANIMEDB_PLUGINS_COUNT 3', $nsh);

        // Checked by default: local and translation (indexes 0 and 1), not integration (index 2).
        $this->assertStringContainsString('${NSD_Check} $AnimedbPlugin0', $nsh);
        $this->assertStringContainsString('${NSD_Check} $AnimedbPlugin1', $nsh);
        $this->assertStringNotContainsString('${NSD_Check} $AnimedbPlugin2', $nsh);

        // Versions are never baked in.
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d+\.\d+/', $nsh);
    }

    public function testOutputStartsWithGeneratedNoticeAndLicenseHeader(): void
    {
        $this->generate($this->registry([$this->plugin('animedb-local', 'Local files', 'local', '>=2.0.0')]));

        $nsh = $this->generated();
        $this->assertStringStartsWith('; GENERATED', $nsh);
        $this->assertStringContainsString('DO NOT EDIT', explode("\n", $nsh)[0]);
        $this->assertStringContainsString('GNU General Public License', $nsh);
        $this->assertStringContainsString('GPL-3.0-or-later', $nsh);
    }

    public function testEscapesNsisSpecialCharactersInPluginNames(): void
    {
        $this->generate($this->registry([$this->plugin('animedb-odd', 'A & "B" $x', 'local', '>=2.0.0')]));

        $this->assertStringContainsString('"A && $\"B$\" $$x"', $this->generated());
    }

    public function testNoCompatiblePluginsSucceedsWithoutDeclaringThePage(): void
    {
        $tester = $this->generate($this->registry([
            $this->plugin('animedb-future', 'Future', 'integration', '>=99.0.0'),
            $this->plugin('thirdparty-tool', 'Third party', 'local', '>=2.0.0'),
        ]));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('GPL-3.0-or-later', $this->generated());
        $this->assertStringNotContainsString('ANIMEDB_PLUGINS_PAGE', $this->generated());
    }

    public function testUnreachableRegistryFailsTheCommandAndWritesNothing(): void
    {
        $httpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null);

        $tester = $this->execute($httpClient);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function testRegistryWithUntrustedSignatureFailsTheCommandAndWritesNothing(): void
    {
        $json = $this->registry([$this->plugin('animedb-local', 'Local files', 'local', '>=2.0.0')]);
        $foreignKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $httpClient = $this->serving($json, base64_encode(sodium_crypto_sign_detached($json, $foreignKey)));

        $tester = $this->execute($httpClient);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function testFailsWhenCoreVersionIsUnknown(): void
    {
        $json = $this->registry([$this->plugin('animedb-local', 'Local files', 'local', '>=2.0.0')]);

        $tester = $this->execute($this->serving($json, $this->sign($json)), coreVersion: null);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function testLeavesNoRegistryCacheOrHighWaterMarkBehind(): void
    {
        $before = glob(sys_get_temp_dir().'/anime-installer-plugins-*') ?: [];

        $this->generate($this->registry([$this->plugin('animedb-local', 'Local files', 'local', '>=2.0.0')]));

        $this->assertSame([], glob($this->workDir.'/app/var/*'), 'Nothing may be written under var/.');
        $this->assertSame($before, glob(sys_get_temp_dir().'/anime-installer-plugins-*') ?: [], 'The temporary state directory must be removed.');
    }

    public function testTemporaryStateIsRemovedWhenTheRegistryIsUnavailable(): void
    {
        $before = glob(sys_get_temp_dir().'/anime-installer-plugins-*') ?: [];

        $this->execute(new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null));

        $this->assertSame($before, glob(sys_get_temp_dir().'/anime-installer-plugins-*') ?: []);
    }

    /**
     * @param list<array<string, mixed>> $plugins
     */
    private function registry(array $plugins): string
    {
        return json_encode(['sequence' => 7, 'asset_mirrors' => [], 'plugins' => $plugins], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function plugin(string $id, string $name, string $type, string $core): array
    {
        $manifest = [
            'id' => $id,
            'name' => $name,
            'version' => '1.4.2',
            'type' => $type,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ];
        if ($type === 'integration') {
            $manifest['features'] = ['filler' => true];
        }
        if ($type === 'translation') {
            $manifest['locales'] = ['de'];
        }

        return [
            'id' => $id,
            'manifest' => $manifest,
            'versions' => [['version' => '1.4.2', 'core' => $core, 'sha256' => 'sha-1.4.2']],
        ];
    }

    private function generate(string $registryJson): CommandTester
    {
        return $this->execute($this->serving($registryJson, $this->sign($registryJson)));
    }

    private function execute(MockHttpClient $httpClient, ?string $coreVersion = '2.5.0'): CommandTester
    {
        $tester = new CommandTester(new InstallerPluginsGenerateCommand(
            new PluginRegistryFetcher($httpClient),
            new PluginRegistrySignatureVerifier([$this->publicKey]),
            new NullLogger(),
            $coreVersion,
            null,
            $this->workDir.'/app',
        ));
        $tester->execute(['--output' => $this->outputPath]);

        return $tester;
    }

    private function serving(string $registryJson, string $signature): MockHttpClient
    {
        return new MockHttpClient(
            static fn (string $method, string $url): MockResponse => new MockResponse(str_ends_with($url, '.sig') ? $signature : $registryJson),
            null,
        );
    }

    private function sign(string $registryJson): string
    {
        return base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey));
    }

    private function generated(): string
    {
        $contents = file_get_contents($this->outputPath);
        $this->assertIsString($contents);

        return $contents;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (glob($path.'/{,.}[!.]*', \GLOB_BRACE) ?: [] as $child) {
            $this->removeTree($child);
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
