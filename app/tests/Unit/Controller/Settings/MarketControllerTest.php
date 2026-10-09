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
use App\Message\RefreshMarketSnapshotMessage;
use App\Service\AppConfigStore;
use App\Service\Market\MarketAssetDownloader;
use App\Service\Market\MarketRefreshService;
use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotPlugin;
use App\Service\Market\MarketUpdateResolver;
use App\Service\NearestBuiltInLocale;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\Translation\TranslationCoverageService;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
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

    /**
     * The number of keys {@see self::setUp()} writes into the fixture reference catalog — the
     * denominator {@see TranslationCoverageService::referenceKeyCount()}
     * reads for every translation-coverage-badge test below.
     */
    private const int APP_TRANSLATION_KEY_COUNT = 10;

    private string $rootDir;
    private string $pluginsDir;
    private string $snapshotCachePath;
    private string $configPath;
    private InstalledPluginsRegistry $installedPlugins;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-market-controller-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->snapshotCachePath = $this->rootDir.'/market-snapshot-cache.json';
        $this->configPath = $this->rootDir.'/config.json';

        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );

        mkdir($this->rootDir.'/translations', recursive: true);
        file_put_contents(
            $this->rootDir.'/translations/messages.en.yaml',
            implode('', array_map(static fn (int $i): string => \sprintf("key%d: Value %d\n", $i, $i), range(1, self::APP_TRANSLATION_KEY_COUNT))),
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
        ?MessageBusInterface $messageBus = null,
        ?AppConfigStore $configStore = null,
        ?TranslationCoverageService $translationCoverage = null,
        ?string $pluginContractsVersion = null,
    ): MarketController {
        return new MarketController(
            $snapshotCache,
            $assetDownloader,
            $this->installer(),
            $this->installedPlugins,
            self::CORE_VERSION,
            $pluginContractsVersion,
            $csrf ?? $this->alwaysValidCsrf(),
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
            $twig,
            $messageBus ?? $this->alwaysDispatchingMessageBus(),
            $configStore ?? new AppConfigStore($this->configPath),
            $translationCoverage ?? new TranslationCoverageService($this->installedPlugins, $this->rootDir),
            new NearestBuiltInLocale(),
            new MarketUpdateResolver($snapshotCache, self::CORE_VERSION),
        );
    }

    /**
     * Writes {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT} as of $attemptAt, so a
     * test can assert the render-fallback throttle ({@see MarketController}) either suppresses or
     * allows a dispatch depending on how far in the past that marker is.
     */
    private function configStoreWithLastRefreshAttemptAt(\DateTimeImmutable $attemptAt): AppConfigStore
    {
        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static function (array $config) use ($attemptAt): array {
            $config[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT] = $attemptAt->format(\DateTimeInterface::ATOM);

            return $config;
        });

        return $configStore;
    }

    private function alwaysDispatchingMessageBus(): MessageBusInterface
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        return $messageBus;
    }

    /**
     * @param list<MarketSnapshotPlugin> $plugins
     */
    private function snapshot(array $plugins, string $coreVersion = self::CORE_VERSION): MarketSnapshot
    {
        return new MarketSnapshot($coreVersion, 1, [self::DEFAULT_MIRROR], $plugins);
    }

    /**
     * @param list<string>|null $locales
     */
    private function snapshotPlugin(
        string $id,
        ?string $resolvedVersion,
        ?string $sha256,
        string $latestVersion = '1.2.0',
        string $latestVersionCore = '>=2.0.0',
        ?int $translationKeyCount = null,
        string $type = 'integration',
        ?array $locales = null,
        bool $incompatiblePluginContracts = false,
    ): MarketSnapshotPlugin {
        return new MarketSnapshotPlugin($id, $this->manifest($id, $latestVersion, $type), $resolvedVersion, $sha256, $latestVersion, $latestVersionCore, $translationKeyCount, $locales, incompatiblePluginContracts: $incompatiblePluginContracts);
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
    private function manifest(string $id, string $version = '1.2.0', string $type = 'integration'): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($id),
            'version' => $version,
            'type' => $type,
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

    /**
     * Issue #834: `?feature=filler` keeps only the plugins whose *manifest* declares
     * `features.filler = true` — {@see self::snapshotPlugin()} always sets that via
     * {@see self::manifest()}, so this test builds its own {@see MarketSnapshotPlugin} to get one
     * plugin with the feature and one without.
     */
    public function testIndexFiltersToFillerPluginsWhenFeatureFilterIsFiller(): void
    {
        $filler = new MarketSnapshotPlugin(
            'animedb-shikimori',
            ['id' => 'animedb-shikimori', 'name' => 'Shikimori', 'version' => '1.2.0', 'type' => 'integration', 'features' => ['filler' => true]],
            '1.2.0',
            'abc123',
            '1.2.0',
            '>=2.0.0',
        );
        $nonFiller = new MarketSnapshotPlugin(
            'animedb-other',
            ['id' => 'animedb-other', 'name' => 'Other', 'version' => '1.0.0', 'type' => 'integration', 'features' => ['filler' => false]],
            '1.0.0',
            'def456',
            '1.0.0',
            '>=2.0.0',
        );
        $snapshot = $this->snapshot([$filler, $nonFiller]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => \count($params['items']) === 1
                    && $params['items'][0]['plugin']->id === 'animedb-shikimori'
                    && $params['featureFilter'] === 'filler',
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market?feature=filler'));
    }

    public function testIndexIgnoresAnUnknownFeatureFilterValue(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => \count($params['items']) === 1 && $params['featureFilter'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market?feature=unknown'));
    }

    public function testIndexNeverShowsTheFeatureFilterWhenTheRegistryIsUnavailable(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['featureFilter'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market?feature=filler'));
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

    /**
     * Issue #562: the `incompatiblePluginContracts` flag the snapshot already carries must reach
     * the template unchanged — the card-level "not the right plugin-contracts version" hint is
     * driven by this, not recomputed by the controller.
     */
    public function testIndexCarriesTheIncompatiblePluginContractsFlagToTheTemplate(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: null, sha256: null, incompatiblePluginContracts: true),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['plugin']->incompatiblePluginContracts === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Issue #514: a `translation`-type plugin whose resolved version published a key count gets a
     * coverage percentage computed against the app's own reference key count
     * ({@see self::APP_TRANSLATION_KEY_COUNT}), never a value read straight off the registry.
     */
    public function testIndexShowsTranslationCoveragePercentForATranslationPluginWithAKeyCount(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-german', resolvedVersion: '1.2.0', sha256: 'abc123', translationKeyCount: 6, type: 'translation'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['translationCoveragePercent'] === 60,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * A `translation`-type plugin whose resolved version carries no key count at all (published
     * before this field existed, or the version simply is not the one the registry annotated)
     * shows no badge rather than a fabricated 0%.
     */
    public function testIndexShowsNoTranslationCoverageForATranslationPluginWithoutAKeyCount(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-german', resolvedVersion: '1.2.0', sha256: 'abc123', translationKeyCount: null, type: 'translation'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['translationCoveragePercent'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * An `integration` plugin's strings live in their own domain, not the app's `messages`
     * catalog — a key count on such a plugin's registry entry (a publishing mistake, or a future
     * unrelated use of the field) must not produce a coverage badge.
     */
    public function testIndexShowsNoTranslationCoverageForANonTranslationPluginEvenWithAKeyCount(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123', translationKeyCount: 6, type: 'integration'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['translationCoveragePercent'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * A plugin catalog with more keys than the app itself has (e.g. it also carries keys for a
     * locale variant this app version does not) must clamp to 100%, not report a number above it.
     */
    public function testIndexClampsTranslationCoveragePercentAt100WhenThePluginKeyCountExceedsTheAppsOwn(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-german', resolvedVersion: '1.2.0', sha256: 'abc123', translationKeyCount: self::APP_TRANSLATION_KEY_COUNT * 2, type: 'translation'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['translationCoveragePercent'] === 100,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Issue #543 acceptance criterion: a version entry that never published a `locales` list (a
     * version published before this field existed) must render no locale badge at all —
     * "unknown" must not be shown as "no languages".
     */
    public function testIndexHidesTheLocaleListWhenTheResolvedVersionDoesNotCarryOne(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123', locales: null),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['locales'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Issue #543 acceptance criterion: at a "de" interface locale, a non-translation
     * ("integration"/"local") plugin whose resolved version does not carry "de" is marked as not
     * being translated into the interface language.
     */
    public function testIndexMarksAFeaturePluginAsMissingTheInterfaceLocaleAtADifferentLocale(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123', type: 'integration', locales: ['en', 'ru']),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['interfaceLocaleMissing'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market');
        $request->setLocale('de');
        $controller->index($request);
    }

    /**
     * Issue #543 acceptance criterion: the same missing-locale check does not raise a warning for
     * a `translation`-type plugin whose resolved version carries the interface locale.
     */
    public function testIndexDoesNotWarnATranslationPluginThatCarriesTheInterfaceLocale(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-german', resolvedVersion: '1.2.0', sha256: 'abc123', type: 'translation', locales: ['de', 'ja']),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['interfaceLocaleMissing'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market');
        $request->setLocale('de');
        $controller->index($request);
    }

    /**
     * Issue #543 acceptance criterion: a feature ("integration"/"local") plugin whose resolved
     * version DOES carry the interface locale must not be flagged as missing it.
     */
    public function testIndexDoesNotWarnAFeaturePluginThatCarriesTheInterfaceLocale(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123', type: 'integration', locales: ['en', 'ru']),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['interfaceLocaleMissing'] === false
                    && $params['items'][0]['localeInfo']['missingFallbackLocale'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market');
        $request->setLocale('en');
        $controller->index($request);
    }

    /**
     * A `translation`-type plugin whose resolved version does not carry the interface locale at
     * all must still not be warned — a language pack not covering the interface's current
     * language is the normal, expected case for a translation plugin (issue #543), unlike a
     * feature plugin.
     */
    public function testIndexDoesNotWarnATranslationPluginMissingTheInterfaceLocaleEntirely(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-german', resolvedVersion: '1.2.0', sha256: 'abc123', type: 'translation', locales: ['de']),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['interfaceLocaleMissing'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market');
        $request->setLocale('ru');
        $controller->index($request);
    }

    /**
     * Issue #543 acceptance criterion: a feature plugin carrying only "ru" gets flagged as not
     * carrying the fallback locale when the interface locale is "de" — "de" resolves (issue #538)
     * to the fallback chain ["en"], which "ru" is not part of.
     */
    public function testIndexMarksAFeaturePluginAsMissingTheFallbackLocaleWhenNeitherItNorTheChainIsCarried(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123', type: 'integration', locales: ['ru']),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['missingFallbackLocale'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market');
        $request->setLocale('de');
        $controller->index($request);
    }

    /**
     * Same plugin as above, but at a "kk" interface locale — its fallback chain is ["ru", "en"]
     * (issue #538), which "ru" IS part of, so the plugin must not be flagged.
     */
    public function testIndexDoesNotMarkAFeaturePluginAsMissingTheFallbackLocaleWhenTheChainCoversIt(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123', type: 'integration', locales: ['ru']),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['items'][0]['localeInfo']['missingFallbackLocale'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market');
        $request->setLocale('kk');
        $controller->index($request);
    }

    /**
     * Issue #543 acceptance criterion: when a plugin has no compatible version at all, the
     * storefront shows a page-level summary distinct from that plugin's own "needs core version
     * X" hint.
     */
    public function testIndexShowsAnIncompatiblePluginsSummaryWhenNoVersionResolves(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: null, sha256: null, latestVersionCore: '>=99.0.0'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['hasIncompatiblePlugin'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->controller($this->snapshotCacheServing($snapshot), $this->assetDownloaderServingPluginZip(), $twig);

        $controller->index(Request::create('/settings/market'));
    }

    public function testIndexDoesNotShowAnIncompatiblePluginsSummaryWhenEveryPluginResolves(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['hasIncompatiblePlugin'] === false,
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
     * Render-fallback (issue #440): a missing snapshot must dispatch a background refresh instead
     * of leaving the storefront permanently empty — the read-only HTTP path itself never rebuilds
     * it inline (issue #439).
     */
    public function testIndexDispatchesARefreshWhenTheSnapshotIsMissing(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(RefreshMarketSnapshotMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            messageBus: $messageBus,
        );

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Same render-fallback as above, for a snapshot left over from before an app upgrade
     * (core_version mismatch) rather than a missing one entirely.
     */
    public function testIndexDispatchesARefreshWhenTheSnapshotsCoreVersionIsStale(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ], coreVersion: '2.4.0');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(RefreshMarketSnapshotMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            messageBus: $messageBus,
        );

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Reviewer follow-up on issue #440's render-fallback: flock() in {@see MarketRefreshService}
     * only collapses *concurrent* refreshes, not sequential ones across separate renders, so a
     * persistently unavailable snapshot must not queue an unbounded stream of refresh jobs — one
     * dispatched moments ago must suppress another.
     */
    public function testIndexDoesNotDispatchARefreshWhenARecentAttemptIsAlreadyRecorded(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            messageBus: $messageBus,
            configStore: $this->configStoreWithLastRefreshAttemptAt(new \DateTimeImmutable('-1 minute')),
        );

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * Once the throttle window has elapsed, a still-missing snapshot must dispatch again — the
     * throttle bounds the queue, it does not stop retrying forever.
     */
    public function testIndexDispatchesARefreshAgainOnceTheThrottleWindowHasElapsed(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(RefreshMarketSnapshotMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            messageBus: $messageBus,
            configStore: $this->configStoreWithLastRefreshAttemptAt(new \DateTimeImmutable('-1 hour')),
        );

        $controller->index(Request::create('/settings/market'));
    }

    /**
     * A ready snapshot must not trigger a redundant refresh on every render.
     */
    public function testIndexDoesNotDispatchARefreshWhenTheSnapshotIsReady(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: '1.2.0', sha256: 'abc123'),
        ]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            messageBus: $messageBus,
        );

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

    /**
     * Issue #562 acceptance criterion: when no version resolves specifically because of the
     * `plugin_contracts` axis, install must report a different, honest reason — not the
     * "incompatible core" text, which would wrongly suggest updating the app would help.
     */
    public function testInstallReportsIncompatiblePluginContractsWhenBlockedOnThatAxis(): void
    {
        $snapshot = $this->snapshot([
            $this->snapshotPlugin('animedb-shikimori', resolvedVersion: null, sha256: null, incompatiblePluginContracts: true),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_market.install_error_incompatible_plugin_contracts', $params['installError']);
                self::assertSame(['%currentPluginContracts%' => '0.14.0'], $params['installErrorParams']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = $this->controller(
            $this->snapshotCacheServing($snapshot),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            pluginContractsVersion: '0.14.0',
        );

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

    /**
     * The manual refresh button (issue #441) dispatches unconditionally — unlike the
     * render-fallback throttle in {@see self::testIndexDoesNotDispatchARefreshWhenARecentAttemptIsAlreadyRecorded()},
     * a fresh attempt timestamp must never suppress an explicit click.
     */
    public function testRefreshDispatchesUnconditionallyAndReturnsACheckingFragment(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(RefreshMarketSnapshotMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<div id="market-refresh-area"></div>');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/market/refresh/status?refreshStartedAt=...');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            $urlGenerator,
            configStore: $this->configStoreWithLastRefreshAttemptAt(new \DateTimeImmutable('-1 minute')),
            messageBus: $messageBus,
        );

        $response = $controller->refresh(Request::create('/settings/market/refresh', 'POST', ['_token' => 'token']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRefreshRejectsInvalidCsrfToken(): void
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
        $controller->refresh(Request::create('/settings/market/refresh', 'POST', ['_token' => 'bad']));
    }

    /**
     * When the success timestamp {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT} has moved
     * past the baseline the click captured, the refresh succeeded — the response must carry
     * `HX-Refresh` so the client reloads the page and picks up the fresh snapshot (issue #441's
     * "the storefront shows the fresh snapshot" acceptance criterion).
     */
    public function testRefreshStatusReportsDoneAndTriggersAFullPageRefreshWhenTheSuccessTimestampAdvanced(): void
    {
        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static fn (array $config): array => [
            ...$config,
            MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            configStore: $configStore,
        );

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertSame('true', $response->headers->get('HX-Refresh'));
    }

    /**
     * A returning user already has a non-empty {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT}
     * from a previous refresh — this proves {@see MarketController::refreshStatus()} compares the
     * current timestamp against the *baseline* the click captured, not merely against null. Without
     * that comparison, a poll landing before the new refresh even finishes would see the old,
     * already-non-null timestamp and wrongly report `done`.
     */
    public function testRefreshStatusKeepsCheckingWhenTheSuccessTimestampEqualsTheBaseline(): void
    {
        $previousRefreshAt = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);

        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static fn (array $config): array => [
            ...$config,
            MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT => $previousRefreshAt,
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'checking',
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            configStore: $configStore,
        );

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => $previousRefreshAt,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertNull($response->headers->get('HX-Refresh'));
    }

    /**
     * The same returning-user scenario as above, but the new refresh has actually completed — the
     * current timestamp differs from the (non-empty) baseline, so this must still report `done`.
     */
    public function testRefreshStatusReportsDoneWhenTheSuccessTimestampAdvancedPastANonEmptyBaseline(): void
    {
        $previousRefreshAt = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);
        $newRefreshAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static fn (array $config): array => [
            ...$config,
            MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT => $newRefreshAt,
        ]);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            configStore: $configStore,
        );

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => $previousRefreshAt,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertSame('true', $response->headers->get('HX-Refresh'));
    }

    /**
     * When only the attempt timestamp moved, the refresh ran and failed — no `HX-Refresh`, the
     * client shows a soft error and stops polling.
     */
    public function testRefreshStatusReportsFailedWithoutTriggeringAFullPageRefreshWhenOnlyTheAttemptTimestampAdvanced(): void
    {
        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static fn (array $config): array => [
            ...$config,
            MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'failed',
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            configStore: $configStore,
        );

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => null,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertNull($response->headers->get('HX-Refresh'));
    }

    /**
     * A returning user already has a non-empty {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT}
     * from a previous refresh — this proves the failure check also compares against the baseline,
     * not merely against null. Without that comparison, a poll landing before the new attempt even
     * finishes would see the old, already-non-null attempt timestamp and wrongly report `failed`.
     */
    public function testRefreshStatusKeepsCheckingWhenTheAttemptTimestampEqualsTheBaseline(): void
    {
        $previousAttemptAt = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);

        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static fn (array $config): array => [
            ...$config,
            MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT => $previousAttemptAt,
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'checking',
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            configStore: $configStore,
        );

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => $previousAttemptAt,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertNull($response->headers->get('HX-Refresh'));
    }

    /**
     * Neither timestamp moved and the polling window elapsed — report a timeout rather than
     * polling forever.
     */
    public function testRefreshStatusReportsTimeoutWhenNeitherTimestampAdvancedWithinTheWindow(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'timeout',
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => null,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertNull($response->headers->get('HX-Refresh'));
    }

    /**
     * The failed/timeout fragments replace the whole control, so they must keep telling the
     * template that no usable catalog is loaded — otherwise the button label reverts to the
     * "check for updates" wording right after an unsuccessful load.
     */
    public function testRefreshStatusFragmentsKeepRegistryUnavailableWhenNoSnapshotIsReady(): void
    {
        $configStore = new AppConfigStore($this->configPath);
        $configStore->update(static fn (array $config): array => [
            ...$config,
            MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->exactly(2))
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['registryUnavailable'] === true,
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing(null),
            $this->assetDownloaderServingPluginZip(),
            $twig,
            configStore: $configStore,
        );

        // failed: the attempt timestamp moved past the (null) baseline.
        $controller->refreshStatus(Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => null,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]));

        // timeout: the baseline equals the current attempt timestamp and the window elapsed.
        $attemptAt = $configStore->read()[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT];
        $controller->refreshStatus(Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => $attemptAt,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
        ]));
    }

    public function testRefreshStatusFragmentIsAvailableWhenTheSnapshotIsReady(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'timeout' && $params['registryUnavailable'] === false,
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller(
            $this->snapshotCacheServing($this->snapshot([])),
            $this->assetDownloaderServingPluginZip(),
            $twig,
        );

        $controller->refreshStatus(Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => null,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
        ]));
    }

    /**
     * Neither timestamp moved yet and the window has not elapsed — keep polling.
     */
    public function testRefreshStatusKeepsCheckingWhenNeitherTimestampAdvancedAndTheWindowHasNotElapsed(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/market/_refresh_area.html.twig', $this->callback(
                static fn (array $params): bool => $params['state'] === 'checking',
            ))
            ->willReturn('<div id="market-refresh-area"></div>');

        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $twig);

        $request = Request::create('/settings/market/refresh/status', 'GET', [
            'refreshBaselineAt' => null,
            'refreshBaselineAttemptAt' => null,
            'refreshStartedAt' => (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::ATOM),
        ]);
        $response = $controller->refreshStatus($request);

        $this->assertNull($response->headers->get('HX-Refresh'));
    }

    public function testRefreshStatusRejectsAMissingOrInvalidStartedAt(): void
    {
        $controller = $this->controller($this->snapshotCacheServing(null), $this->assetDownloaderServingPluginZip(), $this->createStub(Environment::class));

        $this->expectException(BadRequestHttpException::class);
        $controller->refreshStatus(Request::create('/settings/market/refresh/status', 'GET', ['refreshStartedAt' => 'not-a-date']));
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
