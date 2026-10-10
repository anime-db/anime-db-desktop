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

use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Controller\Settings\PluginController;
use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Market\MarketSnapshot;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotPlugin;
use App\Service\Market\MarketUpdateResolver;
use App\Service\Plugin\DefaultSearchPluginRegistry;
use App\Service\Plugin\DefaultSearchPluginSelection;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\SyncRegistry;
use App\Service\Plugin\SyncSeedDispatcher;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\Translation\TranslationCoverageService;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PluginControllerTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';

    private string $rootDir;
    private string $pluginsDir;
    private string $fixturesDir;
    private InstalledPluginsRegistry $registry;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-plugin-settings-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->fixturesDir = sys_get_temp_dir().'/anime-plugin-settings-fixtures-'.uniqid();
        mkdir($this->fixturesDir, recursive: true);

        $this->registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
        $this->removeDirectory($this->fixturesDir);
    }

    private function installer(?string $pluginContractsVersion = null): ZipPluginInstaller
    {
        return new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            new PluginCacheWarmer($this->pluginsDir, \dirname(__DIR__, 4), new NullLogger()),
            $this->createStub(WsPublisher::class),
            $pluginContractsVersion,
        );
    }

    /** @param array<string, SettingsPageInterface> $pages */
    private function settingsPages(array $pages = []): SettingsPageRegistry
    {
        $locator = new ServiceLocator(array_map(
            static fn (SettingsPageInterface $page): \Closure => static fn (): SettingsPageInterface => $page,
            $pages,
        ));

        return new SettingsPageRegistry($this->registry, $locator);
    }

    private function alwaysValidCsrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    private function stubUrlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/plugins');

        return $urlGenerator;
    }

    private function controller(
        ?SettingsPageRegistry $settingsPages = null,
        ?ZipPluginInstaller $installer = null,
        ?PluginRemover $remover = null,
        ?TranslationCoverageService $translationCoverage = null,
        ?WsPublisher $wsPublisher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
        ?MarketSnapshotCache $snapshotCache = null,
        ?SyncRegistry $syncRegistry = null,
        ?MessageBusInterface $messageBus = null,
        ?DefaultSearchPluginSelection $defaultSearch = null,
    ): PluginController {
        return new PluginController(
            $this->registry,
            $settingsPages ?? $this->settingsPages(),
            $installer ?? $this->installer(),
            $remover ?? new PluginRemover($this->registry, new \App\Service\Plugin\PluginCacheDirectories(sys_get_temp_dir().'/anime-plugin-cache-unused', new NullLogger())),
            $translationCoverage ?? new TranslationCoverageService($this->registry, $this->rootDir),
            $wsPublisher ?? $this->createStub(WsPublisher::class),
            $csrfTokenManager ?? $this->alwaysValidCsrf(),
            $urlGenerator ?? $this->stubUrlGenerator(),
            $twig ?? $this->createStub(Environment::class),
            new MarketUpdateResolver($snapshotCache ?? new MarketSnapshotCache($this->rootDir.'/market-snapshot-cache.json'), self::CORE_VERSION),
            $syncRegistry ?? new SyncRegistry([], new PluginsConfigStore($this->pluginsDir.'/plugins.json')),
            new SyncSeedDispatcher(
                new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
                $messageBus ?? $this->createStub(MessageBusInterface::class),
                new NullLogger(),
            ),
            $defaultSearch ?? $this->defaultSearchSelection(),
        );
    }

    /** @param array<string, SearchByPluginInterface> $plugins */
    private function defaultSearchSelection(?AppSettingsProvider $settings = null, array $plugins = []): DefaultSearchPluginSelection
    {
        $settings ??= new AppSettingsProvider(new AppConfigStore($this->rootDir.'/default-search-config.json'));
        $store = new PluginsConfigStore($this->pluginsDir.'/plugins.json');

        return new DefaultSearchPluginSelection($plugins, $store, $settings, new DefaultSearchPluginRegistry($plugins, $store, $settings));
    }

    /** @param list<string> $syncPluginIds */
    private function syncRegistry(array $syncPluginIds): SyncRegistry
    {
        $syncs = [];
        foreach ($syncPluginIds as $id) {
            $syncs[$id] = $this->createStub(SyncInterface::class);
        }

        return new SyncRegistry($syncs, new PluginsConfigStore($this->pluginsDir.'/plugins.json'));
    }

    /** @return array<string, mixed> */
    private function readPluginsJson(): array
    {
        $path = $this->pluginsDir.'/plugins.json';

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    /** @param array<string, mixed> $config */
    private function writePluginsJson(array $config): void
    {
        file_put_contents($this->pluginsDir.'/plugins.json', (string) json_encode($config));
    }

    private function syncToggleRequest(string $pluginId, string $active): Request
    {
        return Request::create('/settings/plugins/'.$pluginId.'/sync', 'POST', ['_token' => 'token', 'active' => $active]);
    }

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', $this->validManifestJson($pluginId, $name));
    }

    /**
     * @param list<string> $locales
     */
    private function writeTranslationPluginManifest(string $pluginId, string $name, array $locales): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => $locales,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function validManifestJson(
        string $pluginId,
        string $name = 'Plugin',
        string $version = '1.0.0',
        string $requireCore = '>=2.0.0',
        ?string $requirePluginContracts = null,
    ): string {
        $require = ['core' => $requireCore, 'php' => '>=8.2'];
        if ($requirePluginContracts !== null) {
            $require['plugin-contracts'] = $requirePluginContracts;
        }

        return (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => $require,
        ]);
    }

    /**
     * @param array<string, string> $files relative path within the archive => file contents
     */
    private function createZip(array $files): string
    {
        $zipPath = $this->fixturesDir.'/'.uniqid('plugin-', true).'.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach ($files as $relativePath => $contents) {
            $zip->addFromString($relativePath, $contents);
        }
        $zip->close();

        return $zipPath;
    }

    private function uploadedZip(string $zipPath): UploadedFile
    {
        return new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);
    }

    public function testIndexRendersInstalledPluginsList(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(function (array $params): bool {
                self::assertCount(1, $params['installedPlugins']);
                self::assertSame('animedb-shikimori', (string) $params['installedPlugins'][0]->id);
                self::assertSame([], $params['settingsPluginIds']);
                self::assertNull($params['installedPluginId']);
                self::assertNull($params['removedPluginId']);

                return true;
            }))
            ->willReturn('<html></html>');

        $response = $this->controller(twig: $twig)->index(Request::create('/settings/plugins'));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Issue #561: an incompatible plugin must stay visible on the settings page (with a reason,
     * rendered by `settings/plugins/index.html.twig`), never disappear the way a plugin filtered
     * out of {@see InstalledPluginsRegistry::enabled()} would if the controller used that instead
     * of {@see InstalledPluginsRegistry::all()}.
     */
    public function testIndexKeepsAnIncompatiblePluginListedWithCompatibleFalse(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', $this->validManifestJson('animedb-shikimori', 'Shikimori', requirePluginContracts: '^0.16'));

        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
            pluginContractsVersion: 'v0.15.0',
        );
        $registry->reconcile();
        $this->registry = $registry;

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(function (array $params): bool {
                self::assertCount(1, $params['installedPlugins']);
                self::assertFalse($params['installedPlugins'][0]->compatible);

                return true;
            }))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig)->index(Request::create('/settings/plugins'));
    }

    public function testIndexPassesMarketUpdatesOnlyForIncompatiblePlugins(): void
    {
        foreach (['animedb-shikimori' => '^0.16', 'animedb-anilist' => null] as $id => $contracts) {
            mkdir($this->pluginsDir.'/'.$id, recursive: true);
            file_put_contents($this->pluginsDir.'/'.$id.'/manifest.json', $this->validManifestJson($id, $id, requirePluginContracts: $contracts));
        }
        $this->registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
            pluginContractsVersion: 'v0.15.0',
        );
        $this->registry->reconcile();

        $snapshotCache = new MarketSnapshotCache($this->rootDir.'/market-snapshot-cache.json');
        $snapshotCache->store(new MarketSnapshot(self::CORE_VERSION, 1, [], [
            new MarketSnapshotPlugin('animedb-shikimori', ['id' => 'animedb-shikimori'], '1.1.0', 'sha', '1.1.0', '>=2.0.0'),
            new MarketSnapshotPlugin('animedb-anilist', ['id' => 'animedb-anilist'], '5.0.0', 'sha', '5.0.0', '>=2.0.0'),
        ]));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(function (array $params): bool {
                self::assertSame(['animedb-shikimori' => '1.1.0'], $params['marketUpdates']);

                return true;
            }))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig, snapshotCache: $snapshotCache)->index(Request::create('/settings/plugins'));
    }

    public function testIndexListsSettingsPluginIdsOnlyForPluginsWithARegisteredAndEnabledSettingsPage(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->writeManifest('animedb-anilist', 'AniList');
        $this->registry->reconcile();

        $settingsPages = $this->settingsPages([
            'animedb-shikimori' => $this->createStub(SettingsPageInterface::class),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['settingsPluginIds'] === ['animedb-shikimori'],
            ))
            ->willReturn('<html></html>');

        $this->controller(settingsPages: $settingsPages, twig: $twig)->index(Request::create('/settings/plugins'));
    }

    /**
     * Acceptance (issue #825): a broken settings-page constructor in one installed plugin must not
     * take down the whole installed-plugins list for every other plugin. `pluginIdsWithASettingsPage()`
     * used to call {@see SettingsPageRegistry::find()} once per installed plugin, which (before the
     * fix) constructed every tagged settings page via `iterator_to_array()` on the very first call —
     * including `animedb-broken`'s, which throws here. Reverting to that shape would make this test
     * throw instead of asserting 200.
     */
    public function testIndexReturns200AndListsOtherPluginsEvenWhenOneInstalledPluginsSettingsPageConstructorThrows(): void
    {
        $this->writeManifest('animedb-broken', 'Broken');
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $workingPage = $this->createStub(SettingsPageInterface::class);
        $locator = new ServiceLocator([
            'animedb-broken' => static fn (): SettingsPageInterface => throw new \RuntimeException('Must not be instantiated by the installed-plugins list.'),
            'animedb-shikimori' => static fn (): SettingsPageInterface => $workingPage,
        ]);
        $settingsPages = new SettingsPageRegistry($this->registry, $locator);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['settingsPluginIds'] === ['animedb-broken', 'animedb-shikimori'],
            ))
            ->willReturn('<html></html>');

        $response = $this->controller(settingsPages: $settingsPages, twig: $twig)->index(Request::create('/settings/plugins'));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Issue #513: a `translation`-type plugin's row gets a per-locale coverage badge computed
     * from {@see TranslationCoverageService} (issue #512); an `integration`-type plugin — whose
     * strings live in their own domain, not `messages` — gets none.
     */
    public function testIndexShowsTranslationCoverageOnlyForTranslationTypePlugins(): void
    {
        mkdir($this->rootDir.'/translations', recursive: true);
        file_put_contents($this->rootDir.'/translations/messages.en.yaml', "welcome: Hello\ngoodbye: Bye\n");

        $this->writeManifest('animedb-shikimori', 'Shikimori');
        mkdir($this->pluginsDir.'/animedb-shikimori/translations', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/translations/messages.en.yaml', "welcome: Hello\n");

        $this->writeTranslationPluginManifest('animedb-german', 'German', ['de']);
        file_put_contents($this->pluginsDir.'/animedb-german/translations/messages.de.yaml', "welcome: Hallo\n");

        $this->registry->reconcile();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertArrayNotHasKey('animedb-shikimori', $params['translationCoverage']);
                self::assertSame(
                    ['de' => ['covered' => 1, 'total' => 2]],
                    $params['translationCoverage']['animedb-german'],
                );

                return true;
            }))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig)->index(Request::create('/settings/plugins'));
    }

    /**
     * Issue #540: the settings page lists which locales a plugin ships for every installed type,
     * not just `translation` — an `integration` plugin's catalog lives in its own
     * `<plugin-id>.<locale>.yaml` domain, so it gets a plain language list with no covered/total
     * figure (that stays exclusive to `translationCoverage`, unchanged from issue #513).
     */
    public function testIndexListsLanguagesForBothPluginTypesWithNoCoverageForFeaturePlugins(): void
    {
        mkdir($this->rootDir.'/translations', recursive: true);
        file_put_contents($this->rootDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $this->writeManifest('animedb-widget', 'Widget');
        mkdir($this->pluginsDir.'/animedb-widget/translations', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-widget/translations/animedb-widget.en.yaml', "welcome: Hello\n");
        file_put_contents($this->pluginsDir.'/animedb-widget/translations/animedb-widget.ru.yaml', "welcome: Привет\n");

        $this->writeTranslationPluginManifest('animedb-german', 'German', ['de']);
        file_put_contents($this->pluginsDir.'/animedb-german/translations/messages.de.yaml', "welcome: Hallo\n");

        $this->registry->reconcile();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertArrayNotHasKey('animedb-widget', $params['translationCoverage']);
                self::assertSame(['en', 'ru'], $params['pluginLocales']['animedb-widget']['locales']);
                self::assertFalse($params['pluginLocales']['animedb-widget']['missingFallbackLocale']);

                self::assertSame(['de'], $params['pluginLocales']['animedb-german']['locales']);

                return true;
            }))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig)->index(Request::create('/settings/plugins'));
    }

    /**
     * Issue #540: with the interface in 'de' (falls back to 'en', issue #538) and a feature
     * plugin shipping only 'ru', neither is among its locales — the page must flag it, since the
     * user would otherwise see that plugin's raw translation keys.
     */
    public function testIndexFlagsAFeaturePluginThatShipsNeitherTheCurrentLocaleNorItsFallback(): void
    {
        mkdir($this->rootDir.'/translations', recursive: true);
        file_put_contents($this->rootDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $this->writeManifest('animedb-widget', 'Widget');
        mkdir($this->pluginsDir.'/animedb-widget/translations', recursive: true);
        file_put_contents($this->pluginsDir.'/animedb-widget/translations/animedb-widget.ru.yaml', "welcome: Привет\n");

        $this->registry->reconcile();

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertTrue($params['pluginLocales']['animedb-widget']['missingFallbackLocale']);

                return true;
            }))
            ->willReturn('<html></html>');

        $request = Request::create('/settings/plugins');
        $request->setLocale('de');

        $this->controller(twig: $twig)->index($request);
    }

    public function testIndexPassesInstalledQueryParameterThrough(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['installedPluginId'] === 'animedb-shikimori',
            ))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig)->index(Request::create('/settings/plugins?installed=animedb-shikimori'));
    }

    public function testIndexPassesRemovedQueryParameterThrough(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['removedPluginId'] === 'animedb-shikimori',
            ))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig)->index(Request::create('/settings/plugins?removed=animedb-shikimori'));
    }

    public function testInstallRedirectsToIndexWithInstalledPluginIdOnSuccess(): void
    {
        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori')]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index', ['installed' => 'animedb-shikimori'])
            ->willReturn('/settings/plugins?installed=animedb-shikimori');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $response = $this->controller(urlGenerator: $urlGenerator)->install($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/plugins?installed=animedb-shikimori', $response->getTargetUrl());
        $this->assertTrue($this->registry->has(new PluginId('animedb-shikimori')));
        $this->assertFileDoesNotExist($zipPath);
    }

    /**
     * `installIndex()` (issue #822) is the GET counterpart of the install form's own page, split
     * out of the installed-plugins list — it must render the same template as a fresh, error-free
     * form, the way `install()`'s own no-error path would.
     */
    public function testInstallIndexRendersTheInstallFormWithNoError(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', [
                'installError' => null,
                'installErrorParams' => [],
                'syntaxErrors' => [],
                'manifestErrors' => [],
            ])
            ->willReturn('<html></html>');

        $response = $this->controller(twig: $twig)->installIndex();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInstallReRendersWithNoFileErrorWhenNoFileIsUploaded(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_plugins.install_error_no_file',
            ))
            ->willReturn('<html></html>');

        $response = $this->controller(twig: $twig)->install(Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInstallReportsIncompatibleCoreVersionDetails(): void
    {
        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', requireCore: '>=99.0.0')]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_plugins.install_error_incompatible_core', $params['installError']);
                self::assertSame(
                    ['%requiredCore%' => '>=99.0.0', '%currentCore%' => self::CORE_VERSION],
                    $params['installErrorParams'],
                );

                return true;
            }))
            ->willReturn('<html></html>');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $this->controller(twig: $twig)->install($request);
    }

    public function testInstallReportsIncompatiblePluginContractsVersionDetails(): void
    {
        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', requirePluginContracts: '^0.16')]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_plugins.install_error_incompatible_plugin_contracts', $params['installError']);
                self::assertSame(
                    ['%requiredPluginContracts%' => '^0.16', '%installedPluginContracts%' => 'v0.15.0'],
                    $params['installErrorParams'],
                );

                return true;
            }))
            ->willReturn('<html></html>');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $installer = $this->installer(pluginContractsVersion: 'v0.15.0');

        $this->controller(installer: $installer, twig: $twig)->install($request);
    }

    public function testInstallReportsSyntaxErrorDetails(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
            'src/Plugin.php' => "<?php\n\nfinal class Plugin\n{\n",
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_plugins.install_error_syntax', $params['installError']);
                self::assertCount(1, $params['syntaxErrors']);
                self::assertSame('src/Plugin.php', $params['syntaxErrors'][0]->relativePath);

                return true;
            }))
            ->willReturn('<html></html>');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $this->controller(twig: $twig)->install($request);
    }

    /**
     * Re-uploading a ZIP whose manifest id is already installed is treated as an update request
     * (issue #224), not a blocking "already installed" error: the same install form is the
     * explicit user action that authorizes it.
     */
    public function testInstallOfAnAlreadyInstalledPluginIdUpdatesItInstead(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', version: '2.0.0')]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index', ['updated' => 'animedb-shikimori'])
            ->willReturn('/settings/plugins?updated=animedb-shikimori');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $response = $this->controller(urlGenerator: $urlGenerator)->install($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/plugins?updated=animedb-shikimori', $response->getTargetUrl());

        $installed = $this->registry->get(new PluginId('animedb-shikimori'));
        $this->assertNotNull($installed);
        $this->assertSame('2.0.0', $installed->manifest->version);
    }

    /**
     * A plugin's settings live in plugins.json, entirely outside its directory, so they must
     * survive an update untouched even while the directory is briefly swapped (issue #224).
     */
    public function testUpdateViaReuploadPreservesExistingPluginSettings(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $configStore = new PluginsConfigStore($this->pluginsDir.'/plugins.json');
        $configStore->updatePluginSettings(new PluginId('animedb-shikimori'), static fn (array $settings): array => [
            ...$settings,
            'settings' => ['token' => 'secret-oauth-token'],
        ]);

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', version: '2.0.0')]);

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $this->controller()->install($request);

        $this->assertSame(
            ['token' => 'secret-oauth-token'],
            $configStore->getSettingsStorePayload(new PluginId('animedb-shikimori')),
        );
    }

    public function testInstallReportsInvalidManifestDetails(): void
    {
        $zipPath = $this->createZip(['manifest.json' => '{not valid json']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', $this->callback(
                static fn (array $params): bool => $params['installError'] === 'settings_plugins.install_error_invalid_manifest',
            ))
            ->willReturn('<html></html>');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $this->controller(twig: $twig)->install($request);
    }

    public function testInstallReportsGenericInstallErrorForCorruptArchive(): void
    {
        $zipPath = $this->fixturesDir.'/corrupt.zip';
        file_put_contents($zipPath, 'this is not a zip archive');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/install.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_plugins.install_error_generic', $params['installError']);
                self::assertSame([], $params['installErrorParams']);

                return true;
            }))
            ->willReturn('<html></html>');

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $this->controller(twig: $twig)->install($request);
    }

    public function testInstallRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->controller(csrfTokenManager: $csrf)->install(Request::create('/settings/plugins/install', 'POST', ['_token' => 'bad']));
    }

    public function testSetDefaultSearchStoresTheChosenPluginAndEmptyClearsIt(): void
    {
        $settings = new AppSettingsProvider(new AppConfigStore($this->rootDir.'/cfg.json'));
        $selection = $this->defaultSearchSelection($settings, ['animedb-shikimori' => $this->createStub(SearchByPluginInterface::class)]);
        $controller = $this->controller(defaultSearch: $selection);

        $response = $controller->setDefaultSearch(Request::create('/x', 'POST', ['_token' => 't', 'plugin' => 'animedb-shikimori']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('animedb-shikimori', (string) $settings->getDefaultSearchPluginId());

        $controller->setDefaultSearch(Request::create('/x', 'POST', ['_token' => 't', 'plugin' => '']));

        $this->assertNull($settings->getDefaultSearchPluginId());
    }

    public function testSetDefaultSearchRejectsAnUnavailablePluginWithoutWriting(): void
    {
        $settings = new AppSettingsProvider(new AppConfigStore($this->rootDir.'/cfg.json'));
        $controller = $this->controller(defaultSearch: $this->defaultSearchSelection($settings));

        try {
            $controller->setDefaultSearch(Request::create('/x', 'POST', ['_token' => 't', 'plugin' => 'animedb-unknown']));
            $this->fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
        }

        $this->assertFileDoesNotExist($this->rootDir.'/cfg.json');
    }

    public function testSelectionReadsStoredIdWithoutPersistingAFallback(): void
    {
        $settings = new AppSettingsProvider(new AppConfigStore($this->rootDir.'/cfg.json'));
        $selection = $this->defaultSearchSelection($settings, ['animedb-anidb' => $this->createStub(SearchByPluginInterface::class)]);

        $this->assertNull($selection->selected());
        $this->assertFileDoesNotExist($this->rootDir.'/cfg.json');

        $settings->setDefaultSearchPluginId(new PluginId('animedb-gone'));
        $this->assertNull($selection->selected());
        $this->assertSame('animedb-gone', (string) $settings->getDefaultSearchPluginId());
    }

    public function testSelectionKeepsAStoredUnavailableChoiceWhenItIsResubmitted(): void
    {
        $settings = new AppSettingsProvider(new AppConfigStore($this->rootDir.'/cfg.json'));
        $settings->setDefaultSearchPluginId(new PluginId('animedb-gone'));
        $selection = $this->defaultSearchSelection($settings, ['animedb-anidb' => $this->createStub(SearchByPluginInterface::class)]);

        $this->assertSame('animedb-gone', (string) $selection->unavailableSelected());
        $this->assertTrue($selection->select(new PluginId('animedb-gone')));
        $this->assertSame('animedb-gone', (string) $settings->getDefaultSearchPluginId());
        $this->assertFalse($selection->select(new PluginId('animedb-other')));
    }

    public function testIndexPassesSearchChoicesWithManifestNamesAndTheStoredSelection(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();
        $settings = new AppSettingsProvider(new AppConfigStore($this->rootDir.'/cfg.json'));
        $settings->setDefaultSearchPluginId(new PluginId('animedb-shikimori'));
        $selection = $this->defaultSearchSelection($settings, [
            'animedb-shikimori' => $this->createStub(SearchByPluginInterface::class),
            'animedb-uninstalled' => $this->createStub(SearchByPluginInterface::class),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(function (array $params): bool {
                self::assertSame([
                    ['id' => 'animedb-shikimori', 'name' => 'Shikimori'],
                    ['id' => 'animedb-uninstalled', 'name' => 'animedb-uninstalled'],
                ], $params['searchChoices']);
                self::assertSame('animedb-shikimori', $params['selectedSearchId']);
                self::assertSame('', $params['unavailableSearchId']);
                self::assertTrue($params['defaultSearchSaved']);

                return true;
            }))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig, defaultSearch: $selection)->index(Request::create('/settings/plugins', 'GET', ['defaultSearchSaved' => '1']));
    }

    public function testIndexPassesAStoredUnavailableChoiceSeparately(): void
    {
        $settings = new AppSettingsProvider(new AppConfigStore($this->rootDir.'/cfg.json'));
        $settings->setDefaultSearchPluginId(new PluginId('animedb-gone'));
        $selection = $this->defaultSearchSelection($settings, ['animedb-anidb' => $this->createStub(SearchByPluginInterface::class)]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(function (array $params): bool {
                self::assertSame('', $params['selectedSearchId']);
                self::assertSame('animedb-gone', $params['unavailableSearchId']);

                return true;
            }))
            ->willReturn('<html></html>');

        $this->controller(twig: $twig, defaultSearch: $selection)->index(Request::create('/settings/plugins'));
    }

    public function testSetDefaultSearchRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->controller(csrfTokenManager: $csrf)->setDefaultSearch(Request::create('/x', 'POST', ['plugin' => '']));
    }

    public function testRemoveDeletesThePluginAndRedirectsToIndexWithRemovedPluginId(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index', ['removed' => 'animedb-shikimori'])
            ->willReturn('/settings/plugins?removed=animedb-shikimori');

        $response = $this->controller(urlGenerator: $urlGenerator)->remove(
            'animedb-shikimori',
            Request::create('/settings/plugins/animedb-shikimori/remove', 'POST', ['_token' => 'token']),
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/plugins?removed=animedb-shikimori', $response->getTargetUrl());
        $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
    }

    public function testRemovePublishesTheWorkersReloadEventOnSuccess(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ZipPluginInstaller::WORKERS_RELOAD_EVENT, ['pluginId' => 'animedb-shikimori']);

        $this->controller(wsPublisher: $wsPublisher)->remove(
            'animedb-shikimori',
            Request::create('/settings/plugins/animedb-shikimori/remove', 'POST', ['_token' => 'token']),
        );
    }

    public function testRemoveStillRedirectsWhenPublishingTheWorkersReloadEventFails(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $wsPublisher = $this->createStub(WsPublisher::class);
        $wsPublisher->method('publish')->willThrowException(new \RuntimeException('queue unavailable'));

        $response = $this->controller(wsPublisher: $wsPublisher)->remove(
            'animedb-shikimori',
            Request::create('/settings/plugins/animedb-shikimori/remove', 'POST', ['_token' => 'token']),
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertFalse($this->registry->has(new PluginId('animedb-shikimori')));
    }

    public function testRemoveRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->controller(csrfTokenManager: $csrf)->remove(
            'animedb-shikimori',
            Request::create('/settings/plugins/animedb-shikimori/remove', 'POST', ['_token' => 'bad']),
        );
    }

    public function testRemoveRejectsAMalformedPluginId(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->remove(
            'not a valid id',
            Request::create('/settings/plugins/not%20a%20valid%20id/remove', 'POST', ['_token' => 'token']),
        );
    }

    /**
     * @param list<string> $syncPluginIds
     *
     * @return array{0: list<string>, 1: list<string>} syncPluginIds / syncActiveIds given to the template
     */
    private function renderIndexSyncIds(array $syncPluginIds): array
    {
        $captured = ['ids' => [], 'active' => []];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $params) use (&$captured): string {
            $captured = ['ids' => $params['syncPluginIds'], 'active' => $params['syncActiveIds']];

            return '';
        });

        $this->controller(twig: $twig, syncRegistry: $this->syncRegistry($syncPluginIds))->index(Request::create('/settings/plugins'));

        return [$captured['ids'], $captured['active']];
    }

    public function testIndexOffersTheSyncSwitchOnlyForAnEnabledPluginImplementingSyncInterface(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->writeManifest('animedb-anilist', 'AniList');
        $this->writeManifest('animedb-mal', 'MyAnimeList');
        $this->writePluginsJson(['animedb-mal' => ['enabled' => false]]);
        $this->registry->reconcile();

        [$syncPluginIds] = $this->renderIndexSyncIds(['animedb-shikimori', 'animedb-mal']);

        $this->assertSame(['animedb-shikimori'], $syncPluginIds);
    }

    public function testIndexShowsTheSyncSwitchOffForAPluginWithoutAPluginsJsonEntry(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();
        $registry = $this->syncRegistry(['animedb-shikimori']);

        [$syncPluginIds, $syncActiveIds] = $this->renderIndexSyncIds(['animedb-shikimori']);

        $this->assertSame(['animedb-shikimori'], $syncPluginIds);
        $this->assertSame([], $syncActiveIds);
        $this->assertNull($registry->findByPluginId(new PluginId('animedb-shikimori')));
    }

    public function testIndexShowsTheSyncSwitchOnWhenFeaturesSyncIsTrue(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->writePluginsJson(['animedb-shikimori' => ['features' => ['sync' => true]]]);
        $this->registry->reconcile();

        [, $syncActiveIds] = $this->renderIndexSyncIds(['animedb-shikimori']);

        $this->assertSame(['animedb-shikimori'], $syncActiveIds);
    }

    public function testToggleSyncOnWritesFeaturesSyncAndRedirectsToThePluginSettingsPage(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugin_page', ['pluginId' => 'animedb-shikimori'])
            ->willReturn('/settings/plugins/animedb-shikimori');

        // The settings page dispatches the connect-seed itself, so the toggle must not.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $response = $this->controller(
            settingsPages: $this->settingsPages(['animedb-shikimori' => $this->createStub(SettingsPageInterface::class)]),
            urlGenerator: $urlGenerator,
            syncRegistry: $this->syncRegistry(['animedb-shikimori']),
            messageBus: $messageBus,
        )->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));

        $this->assertSame('/settings/plugins/animedb-shikimori', $response->getTargetUrl());
        $this->assertTrue($this->readPluginsJson()['animedb-shikimori']['features']['sync']);
        $this->assertArrayNotHasKey('syncSeeded', $this->readPluginsJson()['animedb-shikimori']);
    }

    public function testToggleSyncOnWithoutSettingsPageStaysOnThePluginsListAndDispatchesSeedOnce(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index')
            ->willReturn('/settings/plugins');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $message): bool => $message instanceof SyncSeedMessage && $message->pluginId === 'animedb-shikimori'))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $controller = $this->controller(urlGenerator: $urlGenerator, syncRegistry: $this->syncRegistry(['animedb-shikimori']), messageBus: $messageBus);
        $response = $controller->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));

        $this->assertSame('/settings/plugins', $response->getTargetUrl());
        $config = $this->readPluginsJson()['animedb-shikimori'];
        $this->assertTrue($config['features']['sync']);
        $this->assertTrue($config['syncSeeded']);
    }

    public function testToggleSyncOnWithoutSettingsPageDoesNotReseedAnAlreadySeededPlugin(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->writePluginsJson(['animedb-shikimori' => ['enabled' => true, 'syncSeeded' => true]]);
        $this->registry->reconcile();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index')
            ->willReturn('/settings/plugins');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $response = $this->controller(urlGenerator: $urlGenerator, syncRegistry: $this->syncRegistry(['animedb-shikimori']), messageBus: $messageBus)
            ->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));

        $this->assertSame('/settings/plugins', $response->getTargetUrl());
        $config = $this->readPluginsJson()['animedb-shikimori'];
        $this->assertTrue($config['features']['sync']);
        $this->assertTrue($config['syncSeeded']);
    }

    public function testToggleSyncOffKeepsSyncSeededAndOtherSettingsAndRedirectsToThePluginsPage(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->writePluginsJson(['animedb-shikimori' => [
            'enabled' => true,
            'syncSeeded' => true,
            'token' => 'abc',
            'features' => ['sync' => true, 'filler' => false],
        ]]);
        $this->registry->reconcile();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index')
            ->willReturn('/settings/plugins');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $response = $this->controller(urlGenerator: $urlGenerator, syncRegistry: $this->syncRegistry(['animedb-shikimori']), messageBus: $messageBus)
            ->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '0'));

        $this->assertSame('/settings/plugins', $response->getTargetUrl());
        $this->assertSame([
            'enabled' => true,
            'syncSeeded' => true,
            'token' => 'abc',
            'features' => ['sync' => false, 'filler' => false],
        ], $this->readPluginsJson()['animedb-shikimori']);
    }

    public function testToggleSyncRejectsInvalidCsrfTokenAndLeavesPluginsJsonUntouched(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();
        $before = $this->readPluginsJson();

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        try {
            $this->controller(csrfTokenManager: $csrf, syncRegistry: $this->syncRegistry(['animedb-shikimori']))
                ->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));
            $this->fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            $this->assertSame($before, $this->readPluginsJson());
        }
    }

    public function testToggleSyncRejectsAPluginWithoutSyncInterface(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();
        $before = $this->readPluginsJson();

        try {
            $this->controller()->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));
            $this->fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException) {
            $this->assertSame($before, $this->readPluginsJson());
        }
    }

    public function testToggleSyncRejectsADisabledPlugin(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->writePluginsJson(['animedb-shikimori' => ['enabled' => false]]);
        $this->registry->reconcile();
        $before = $this->readPluginsJson();

        try {
            $this->controller(syncRegistry: $this->syncRegistry(['animedb-shikimori']))
                ->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));
            $this->fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException) {
            $this->assertSame($before, $this->readPluginsJson());
        }
    }

    public function testToggleSyncRedirectsWithBusyRetryErrorWhenTheLockIsHeld(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index', ['error' => 'busy_retry'])
            ->willReturn('/settings/plugins?error=busy_retry');

        $lockHandle = fopen($this->pluginsDir.'/plugins.json.lock', 'c');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX));

        try {
            $response = $this->controller(urlGenerator: $urlGenerator, syncRegistry: $this->syncRegistry(['animedb-shikimori']))
                ->toggleSync('animedb-shikimori', $this->syncToggleRequest('animedb-shikimori', '1'));
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }

        $this->assertSame('/settings/plugins?error=busy_retry', $response->getTargetUrl());
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
