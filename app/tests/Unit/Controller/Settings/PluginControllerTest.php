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

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use App\Controller\Settings\PluginController;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmer;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\Translation\TranslationCoverageService;
use App\Service\WsPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    private function installer(): ZipPluginInstaller
    {
        return new ZipPluginInstaller(
            $this->pluginsDir,
            self::CORE_VERSION,
            $this->registry,
            new PluginCacheWarmer($this->pluginsDir, \dirname(__DIR__, 4), new NullLogger()),
            $this->createStub(WsPublisher::class),
        );
    }

    /** @param iterable<string, SettingsPageInterface> $pages */
    private function settingsPages(iterable $pages = []): SettingsPageRegistry
    {
        return new SettingsPageRegistry($pages, $this->registry);
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
    ): PluginController {
        return new PluginController(
            $this->registry,
            $settingsPages ?? $this->settingsPages(),
            $installer ?? $this->installer(),
            $remover ?? new PluginRemover($this->registry),
            $translationCoverage ?? new TranslationCoverageService($this->registry, $this->rootDir),
            $wsPublisher ?? $this->createStub(WsPublisher::class),
            $csrfTokenManager ?? $this->alwaysValidCsrf(),
            $urlGenerator ?? $this->stubUrlGenerator(),
            $twig ?? $this->createStub(Environment::class),
        );
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

    private function validManifestJson(string $pluginId, string $name = 'Plugin', string $version = '1.0.0', string $requireCore = '>=2.0.0'): string
    {
        return (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => $requireCore, 'php' => '>=8.2'],
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
                self::assertNull($params['installError']);

                return true;
            }))
            ->willReturn('<html></html>');

        $response = $this->controller(twig: $twig)->index(Request::create('/settings/plugins'));

        $this->assertSame(200, $response->getStatusCode());
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
     * Issue #513: a `translation`-type plugin's row gets a per-locale coverage badge computed
     * from {@see TranslationCoverageService} (issue #512); an `integration`-type plugin — whose
     * strings live in their own domain, not `messages` — gets none.
     */
    public function testIndexShowsTranslationCoverageOnlyForTranslationTypePlugins(): void
    {
        mkdir($this->rootDir.'/translations', recursive: true);
        file_put_contents($this->rootDir.'/translations/messages.en.yaml', "welcome: Hello\ngoodbye: Bye\n");

        $this->writeManifest('animedb-shikimori', 'Shikimori');

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

    public function testInstallReRendersWithNoFileErrorWhenNoFileIsUploaded(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
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
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
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

    public function testInstallReportsSyntaxErrorDetails(): void
    {
        $zipPath = $this->createZip([
            'manifest.json' => $this->validManifestJson('animedb-shikimori'),
            'src/Plugin.php' => "<?php\n\nfinal class Plugin\n{\n",
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
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
            ->with('settings/plugins/index.html.twig', $this->callback(
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
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
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
