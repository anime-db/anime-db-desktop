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
use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

/**
 * Issue #439 moved this controller off a live registry fetch onto reading an already-built
 * {@see MarketSnapshot} — every test below writes its fixture snapshot straight to a
 * {@see MarketSnapshotCache} instead of standing up a signed `plugins-registry.json` document the
 * way {@see \App\Tests\Unit\Service\Market\MarketSnapshotBuilderTest} still does for the builder
 * itself; that keeps this suite focused on the controller's own read/join/render logic.
 */
final class MarketControllerTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';
    private const string PLUGIN_ZIP_CONTENT = 'trusted market plugin archive bytes';
    private const string DEFAULT_MIRROR = 'https://mirror.example/<id>/<version>/<file>';

    private string $rootDir;
    private string $pluginsDir;
    private string $snapshotCachePath;
    private InstalledPluginsRegistry $installedPlugins;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-market-controller-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->snapshotCachePath = $this->rootDir.'/market-snapshot-cache.json';

        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
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

    private function controller(
        MarketSnapshotCache $snapshotCache,
        MarketAssetDownloader $assetDownloader,
        Environment $twig,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?CsrfTokenManagerInterface $csrf = null,
    ): MarketController {
        return new MarketController(
            $snapshotCache,
            $assetDownloader,
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $csrf ?? $this->alwaysValidCsrf(),
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );
    }

    /**
     * @param list<MarketSnapshotPlugin> $plugins
     */
    private function snapshot(array $plugins, string $coreVersion = self::CORE_VERSION): MarketSnapshot
    {
        return new MarketSnapshot($coreVersion, 1, [self::DEFAULT_MIRROR], $plugins);
    }

    private function snapshotPlugin(
        string $id,
        ?string $resolvedVersion,
        ?string $sha256,
        string $latestVersion = '1.2.0',
        string $latestVersionCore = '>=2.0.0',
    ): MarketSnapshotPlugin {
        return new MarketSnapshotPlugin($id, $this->manifest($id, $latestVersion), $resolvedVersion, $sha256, $latestVersion, $latestVersionCore);
    }

    /**
     * Writes $snapshot straight to the cache file {@see MarketController} reads — $snapshot ===
     * null leaves the file absent, the same "no snapshot has ever been built yet" state
     * {@see MarketSnapshotCache::load()} reports.
     */
    private function snapshotCacheServing(?MarketSnapshot $snapshot): MarketSnapshotCache
    {
        $cache = new MarketSnapshotCache($this->snapshotCachePath);
        if ($snapshot !== null) {
            $cache->store($snapshot);
        }

        return $cache;
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
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(function (array $params): bool {
                self::assertFalse($params['registryUnavailable']);
                self::assertCount(1, $params['items']);
                self::assertSame('animedb-shikimori', $params['items'][0]['plugin']->id);
                self::assertSame('1.2.0', $params['items'][0]['plugin']->resolvedVersion);
                self::assertFalse($params['items'][0]['installed']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexMarksIncompatiblePluginsWithNoResolvedVersion(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: null, sha256: null, latestVersionCore: '>=99.0.0'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['plugin']->resolvedVersion === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

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

        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * A snapshot built for a core version other than the one this controller runs against (e.g.
     * left over from before an app upgrade, not yet refreshed for the new one) must be treated the
     * same as no snapshot at all — resolving from it would render stale compatibility data instead
     * of the "not ready yet" state.
     */
    public function testIndexTreatsACoreVersionMismatchAsUnavailableRatherThanResolvingStaleData(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ], coreVersion: '2.4.0');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['registryUnavailable'] === true && $params['items'] === [],
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Issue #439's core acceptance criterion: rendering the storefront makes zero network calls
     * now that it only ever reads the pre-built snapshot.
     */
    public function testIndexMakesNoNetworkCalls(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            new MarketAssetDownloader($httpClient),
            $twig,
        );

        $response = $controller->index(Request::create('/settings/market'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInstallRedirectsToIndexWithInstalledPluginIdOnSuccess(): void
    {
        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '1.2.0');
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: hash('sha256', $zipBytes)),
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_market_index', ['installed' => 'animedb-shikimori'])
            ->willReturn('/settings/market?installed=animedb-shikimori');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServing($zipBytes),
            $this->createStub(Environment::class),
            $urlGenerator,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $response = $controller->install('animedb-shikimori', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/market?installed=animedb-shikimori', $response->getTargetUrl());
        $this->assertTrue($this->installedPlugins->has(new PluginId('animedb-shikimori')));
    }

    /**
     * The snapshot's `resolvedVersion` may differ from its `latestVersion` (an older version still
     * compatible with the current core, see {@see \App\Service\Market\MarketSnapshotBuilder}) —
     * install must fetch that resolved version, not silently upgrade to the latest one.
     */
    public function testInstallDownloadsTheSnapshotsResolvedVersionRatherThanTheLatestOne(): void
    {
        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '1.1.0');
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.1.0', sha256: hash('sha256', $zipBytes), latestVersion: '1.2.0'),
        ]);

        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls, $zipBytes): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse($zipBytes);
        }, null);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/market?installed=animedb-shikimori');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            new MarketAssetDownloader($httpClient),
            $this->createStub(Environment::class),
            $urlGenerator,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);

        $this->assertSame(['https://mirror.example/animedb-shikimori/1.1.0/plugin.zip'], $requestedUrls);
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

        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: hash('sha256', $zipBytes)),
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/market?installed=animedb-shikimori');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServing($zipBytes),
            $this->createStub(Environment::class),
            $urlGenerator,
        );

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $response = $controller->install('animedb-shikimori', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($this->installedPlugins->has(new PluginId('animedb-shikimori')));
    }

    public function testInstallReportsIncompatibleCoreWhenNoVersionResolves(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: null, sha256: null, latestVersionCore: '>=99.0.0'),
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

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testInstallReportsUnknownPluginWhenNotInRegistry(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_market.install_error_unknown_plugin',
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

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

        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testInstallReportsDownloadFailureWhenTheChecksumDoesNotMatch(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'does-not-match-anything'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_market.install_error_download_failed',
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testInstallReportsAlreadyInstalledPluginId(): void
    {
        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '2.0.0');
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '2.0.0', sha256: hash('sha256', $zipBytes), latestVersion: '2.0.0'),
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

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServing($zipBytes), $twig);

        $request = Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'token']);
        $controller->install('animedb-shikimori', $request);
    }

    public function testIndexMarksUpdateAvailableWhenAResolvedVersionIsNewerThanTheInstalledOne(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.1.0')));
        $this->installedPlugins->reconcile();

        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
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

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotMarkUpdateAvailableWhenTheInstalledVersionIsAlreadyTheResolvedOne(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.2.0')));
        $this->installedPlugins->reconcile();

        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
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

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotMarkUpdateAvailableWhenTheResolvedVersionIsOlderThanTheInstalledOne(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '2.0.0')));
        $this->installedPlugins->reconcile();

        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.5.0', sha256: 'abc123'),
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

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotMarkUpdateAvailableWhenVersionsAreSemanticallyEqualButWrittenDifferently(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.0')));
        $this->installedPlugins->reconcile();

        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.0.0', sha256: 'abc123'),
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

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    public function testUpdateRedirectsToIndexWithUpdatedPluginIdOnSuccess(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode($this->manifest('animedb-shikimori', '1.1.0')));
        $this->installedPlugins->reconcile();

        $zipBytes = $this->pluginZipBytes('animedb-shikimori', '1.2.0');
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: hash('sha256', $zipBytes)),
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_market_index', ['updated' => 'animedb-shikimori'])
            ->willReturn('/settings/market?updated=animedb-shikimori');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServing($zipBytes),
            $this->createStub(Environment::class),
            $urlGenerator,
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
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: hash('sha256', $zipBytes)),
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/market?updated=animedb-shikimori');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServing($zipBytes),
            $this->createStub(Environment::class),
            $urlGenerator,
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

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $this->createStub(Environment::class),
            csrf: $csrf,
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->update('animedb-shikimori', Request::create('/settings/market/animedb-shikimori/update', 'POST', ['_token' => 'bad']));
    }

    public function testInstallRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $this->createStub(Environment::class),
            csrf: $csrf,
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->install('animedb-shikimori', Request::create('/settings/market/animedb-shikimori/install', 'POST', ['_token' => 'bad']));
    }

    public function testInstallRejectsAMalformedPluginId(): void
    {
        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $this->createStub(Environment::class));

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
