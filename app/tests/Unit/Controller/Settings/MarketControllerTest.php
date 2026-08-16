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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\MarketController;
use App\Entity\ValueObject\PluginId;
use App\Service\Market\MarketAssetDownloader;
use App\Service\Market\PluginRegistryCache;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistryLoader;
use App\Service\Market\PluginRegistrySignatureVerifier;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * {@see App\Service\Market\PluginRegistryLoader} and {@see MarketAssetDownloader} are both
 * `final`, so — same convention as {@see \App\Tests\Unit\Service\Market\PluginRegistryLoaderTest}
 * and {@see \App\Tests\Unit\Service\Market\MarketAssetDownloaderTest} — this test wires real
 * instances of them against a {@see MockHttpClient} instead of mocking the classes themselves.
 */
final class MarketControllerTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';
    private const string PLUGIN_ZIP_CONTENT = 'trusted market plugin archive bytes';

    private string $rootDir;
    private string $pluginsDir;
    private string $cachePath;
    private InstalledPluginsRegistry $installedPlugins;
    private string $trustedPublicKey;

    /** @var non-empty-string */
    private string $secretKey;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-market-controller-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->cachePath = $this->rootDir.'/market-registry-cache.json';

        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );

        $keyPair = sodium_crypto_sign_keypair();
        $this->trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    private function installer(): ZipPluginInstaller
    {
        return new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->installedPlugins,
            new PluginCacheWarmer($this->pluginsDir, \dirname(__DIR__, 4), new NullLogger()),
            $this->createStub(WsPublisher::class),
        );
    }

    private function alwaysValidCsrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    private function registryLoaderServing(string $registryJson): PluginRegistryLoader
    {
        $signature = base64_encode(sodium_crypto_sign_detached($registryJson, $this->secretKey));

        $httpClient = new MockHttpClient(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '.sig')
                ? new MockResponse($signature)
                : new MockResponse($registryJson),
            null,
        );

        return new PluginRegistryLoader(
            new PluginRegistryFetcher($httpClient),
            new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
            new PluginRegistryCache($this->cachePath),
        );
    }

    private function unavailableRegistryLoader(): PluginRegistryLoader
    {
        $httpClient = new MockHttpClient(function (): never {
            throw new TransportException('Connection refused.');
        }, null);

        return new PluginRegistryLoader(
            new PluginRegistryFetcher($httpClient),
            new PluginRegistrySignatureVerifier([$this->trustedPublicKey]),
            new PluginRegistryCache($this->cachePath),
        );
    }

    private function assetDownloaderServingPluginZip(): MarketAssetDownloader
    {
        return $this->assetDownloaderServing(self::PLUGIN_ZIP_CONTENT);
    }

    private function assetDownloaderServing(string $zipBytes): MarketAssetDownloader
    {
        return new MarketAssetDownloader(new MockHttpClient(
            fn (): MockResponse => new MockResponse($zipBytes),
            null,
        ));
    }

    /**
     * A real ZIP archive (not just placeholder bytes, see {@see self::PLUGIN_ZIP_CONTENT}) for the
     * tests that exercise a real {@see ZipPluginInstaller::install()} call — it needs to actually
     * open and unpack.
     */
    private function pluginZipBytes(string $pluginId, string $version): string
    {
        $zipPath = $this->rootDir.'/'.uniqid('plugin-zip-src-', true).'.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode($this->manifest($pluginId, $version)));
        $zip->close();

        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $bytes;
    }

    /**
     * @param array<string, mixed>                                        $manifest
     * @param list<array{version: string, core: string, sha256?: string}> $versions
     */
    private function registryJson(array $manifest, array $versions): string
    {
        return (string) json_encode([
            'sequence' => 1,
            'asset_mirrors' => ['https://mirror.example/<id>/<version>/<file>'],
            'plugins' => [
                [
                    'id' => $manifest['id'],
                    'manifest' => $manifest,
                    'versions' => $versions,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $id, string $version = '1.2.0'): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($id),
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ];
    }

    public function testIndexRendersPluginsWithResolvedVersionAndInstalledFlag(): void
    {
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=2.1.0 <3.0.0', 'sha256' => 'abc123'],
            ['version' => '1.1.0', 'core' => '>=2.0.0 <3.0.0', 'sha256' => 'def456'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(function (array $params): bool {
                self::assertFalse($params['registryUnavailable']);
                self::assertCount(1, $params['items']);
                self::assertSame('animedb-shikimori', (string) $params['items'][0]['plugin']->id);
                self::assertSame('1.2.0', $params['items'][0]['resolvedVersion']->version);
                self::assertFalse($params['items'][0]['installed']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexMarksIncompatiblePluginsWithNoResolvedVersion(): void
    {
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=99.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['resolvedVersion'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexShowsRegistryUnavailable(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['registryUnavailable'] === true && $params['items'] === [],
            ))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->unavailableRegistryLoader(),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testInstallRedirectsToIndexWithInstalledPluginIdOnSuccess(): void
    {
        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '1.2.0');
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => hash('sha256', $zipBytes)],
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_market_index', ['installed' => 'animedb-shikimori'])
            ->willReturn('/settings/market?installed=animedb-shikimori');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServing($zipBytes),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $response = $controller->install('animedb-shikimori', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/market?installed=animedb-shikimori', $response->getTargetUrl());
        $this->assertTrue($this->installedPlugins->has(new PluginId('animedb-shikimori')));
    }

    public function testInstallSkipsSyntaxLintForAPluginWithSyntaxErrors(): void
    {
        // Issue #220 §4: the market install path must not run the custom-ZIP path's `php -l`
        // lint. A plugin archive with a PHP syntax error would be rejected by
        // ZipPluginInstaller::install() with $trusted = false (see
        // ZipPluginInstallerTest::testInstallBlocksWhenPluginContainsPhpSyntaxError()) — here it
        // must install successfully instead.
        $zipPath = $this->rootDir.'/'.uniqid('plugin-zip-src-', true).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode($this->manifest('animedb-shikimori')));
        $zip->addFromString('src/Plugin.php', "<?php\n\nfinal class Plugin\n{\n"); // unclosed class body
        $zip->close();
        $zipBytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => hash('sha256', $zipBytes)],
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/market?installed=animedb-shikimori');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServing($zipBytes),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $response = $controller->install('animedb-shikimori', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($this->installedPlugins->has(new PluginId('animedb-shikimori')));
    }

    public function testInstallReportsIncompatibleCoreWhenNoVersionResolves(): void
    {
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=99.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_market.install_error_incompatible_core', $params['installError']);
                self::assertSame(
                    ['%requiredCore%' => '>=99.0.0', '%currentCore%' => self::CORE_VERSION],
                    $params['installErrorParams'],
                );

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testInstallReportsUnknownPluginWhenNotInRegistry(): void
    {
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_market.install_error_unknown_plugin',
            ))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/market/animedb-other/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-other', $request);
    }

    public function testInstallReportsRegistryUnavailable(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_market.install_error_registry_unavailable',
            ))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->unavailableRegistryLoader(),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testInstallReportsDownloadFailureWhenTheChecksumDoesNotMatch(): void
    {
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => 'does-not-match-anything'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_market.install_error_download_failed',
            ))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testInstallReportsAlreadyInstalledPluginId(): void
    {
        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '2.0.0');
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '2.0.0'), [
            ['version' => '2.0.0', 'core' => '>=2.0.0', 'sha256' => hash('sha256', $zipBytes)],
        ]);

        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori')));
        $this->installedPlugins->reconcile();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_market.install_error_already_installed', $params['installError']);
                self::assertSame(['%pluginId%' => 'animedb-shikimori'], $params['installErrorParams']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServing($zipBytes),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testIndexMarksUpdateAvailableWhenAResolvedVersionIsNewerThanTheInstalledOne(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.1.0')));
        $this->installedPlugins->reconcile();

        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '1.2.0'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertTrue($params['items'][0]['installed']);
                self::assertTrue($params['items'][0]['updateAvailable']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotMarkUpdateAvailableWhenTheInstalledVersionIsAlreadyTheResolvedOne(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.2.0')));
        $this->installedPlugins->reconcile();

        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '1.2.0'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertTrue($params['items'][0]['installed']);
                self::assertFalse($params['items'][0]['updateAvailable']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotMarkUpdateAvailableWhenTheResolvedVersionIsOlderThanTheInstalledOne(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '2.0.0')));
        $this->installedPlugins->reconcile();

        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '1.5.0'), [
            ['version' => '1.5.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertTrue($params['items'][0]['installed']);
                self::assertFalse($params['items'][0]['updateAvailable']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotMarkUpdateAvailableWhenVersionsAreSemanticallyEqualButWrittenDifferently(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.0')));
        $this->installedPlugins->reconcile();

        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '1.0.0'), [
            ['version' => '1.0.0', 'core' => '>=2.0.0', 'sha256' => 'abc123'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertTrue($params['items'][0]['installed']);
                self::assertFalse($params['items'][0]['updateAvailable']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/market'));
    }

    public function testUpdateRedirectsToIndexWithUpdatedPluginIdOnSuccess(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.1.0')));
        $this->installedPlugins->reconcile();

        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '1.2.0');
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '1.2.0'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => hash('sha256', $zipBytes)],
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_market_index', ['updated' => 'animedb-shikimori'])
            ->willReturn('/settings/market?updated=animedb-shikimori');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServing($zipBytes),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $request = Request::create('/settings/market/animedb-shikimori/update', 'POST', ['_token' => 'token']);
        $response = $controller->update('animedb-shikimori', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/market?updated=animedb-shikimori', $response->getTargetUrl());

        $installed = $this->installedPlugins->get(new PluginId('animedb-shikimori'));
        $this->assertNotNull($installed);
        $this->assertSame('1.2.0', $installed->manifest->version);
    }

    /**
     * A plugin's settings live in plugins.json, entirely outside its directory, so an update must
     * leave them untouched even while the directory is briefly swapped (issue #224).
     */
    public function testUpdatePreservesExistingPluginSettings(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.1.0')));
        $this->installedPlugins->reconcile();

        $configStore = new PluginsConfigStore($this->pluginsDir.'/plugins.json');
        $configStore->updatePluginSettings(new PluginId('animedb-shikimori'), static fn (array $settings): array => [
            ...$settings,
            'settings' => ['token' => 'secret-oauth-token'],
        ]);

        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '1.2.0');
        $registryJson = $this->registryJson($this->manifest('animedb-shikimori', '1.2.0'), [
            ['version' => '1.2.0', 'core' => '>=2.0.0', 'sha256' => hash('sha256', $zipBytes)],
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/market?updated=animedb-shikimori');

        $controller = new MarketController(
            $this->registryLoaderServing($registryJson),
            $this->assetDownloaderServing($zipBytes),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $request = Request::create('/settings/market/animedb-shikimori/update', 'POST', ['_token' => 'token']);
        $controller->update('animedb-shikimori', $request);

        $this->assertSame(
            ['token' => 'secret-oauth-token'],
            $configStore->getSettingsStorePayload(new PluginId('animedb-shikimori')),
        );
    }

    public function testUpdateRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = new MarketController(
            $this->unavailableRegistryLoader(),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $csrf,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->update('animedb-shikimori', Request::create('/settings/market/animedb-shikimori/update', 'POST', ['_token' => 'bad']));
    }

    public function testInstallRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = new MarketController(
            $this->unavailableRegistryLoader(),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $csrf,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->install('animedb-shikimori', Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'bad']));
    }

    public function testInstallRejectsAMalformedPluginId(): void
    {
        $controller = new MarketController(
            $this->unavailableRegistryLoader(),
            $this->assetDownloaderServingPluginZip(),
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->install('Not_Valid!', Request::create('/settings/market/Not_Valid!/install', 'POST', ['_token' => 'token']));
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
