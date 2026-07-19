<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\PluginController;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        return new ZipPluginInstaller($this->pluginsDir, self::CORE_VERSION, $this->registry);
    }

    private function alwaysValidCsrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', $this->validManifestJson($pluginId, $name));
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
                self::assertNull($params['installedPluginId']);
                self::assertNull($params['installError']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $response = $controller->index(Request::create('/settings/plugins'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesInstalledQueryParameterThrough(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => 'animedb-shikimori' === $params['installedPluginId'],
            ))
            ->willReturn('<html></html>');

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $controller->index(Request::create('/settings/plugins?installed=animedb-shikimori'));
    }

    public function testInstallRedirectsToIndexWithInstalledPluginIdOnSuccess(): void
    {
        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori')]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_plugins_index', ['installed' => 'animedb-shikimori'])
            ->willReturn('/settings/plugins?installed=animedb-shikimori');

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $response = $controller->install($request);

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
                static fn (array $params): bool => 'settings_plugins.install_error_no_file' === $params['installError'],
            ))
            ->willReturn('<html></html>');

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $response = $controller->install(Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']));

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

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $controller->install($request);
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

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $controller->install($request);
    }

    public function testInstallReportsAlreadyInstalledPluginId(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->registry->reconcile();

        $zipPath = $this->createZip(['manifest.json' => $this->validManifestJson('animedb-shikimori', version: '2.0.0')]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('settings_plugins.install_error_already_installed', $params['installError']);
                self::assertSame(['%pluginId%' => 'animedb-shikimori'], $params['installErrorParams']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $controller->install($request);
    }

    public function testInstallReportsInvalidManifestDetails(): void
    {
        $zipPath = $this->createZip(['manifest.json' => '{not valid json']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugins/index.html.twig', $this->callback(
                static fn (array $params): bool => 'settings_plugins.install_error_invalid_manifest' === $params['installError'],
            ))
            ->willReturn('<html></html>');

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $controller->install($request);
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

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $request = Request::create('/settings/plugins/install', 'POST', ['_token' => 'token']);
        $request->files->set('plugin_zip', $this->uploadedZip($zipPath));

        $controller->install($request);
    }

    public function testInstallRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = new PluginController(
            $this->registry,
            $this->installer(),
            $csrf,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->install(Request::create('/settings/plugins/install', 'POST', ['_token' => 'bad']));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
