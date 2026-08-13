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
use App\Controller\Settings\PluginSettingsController;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class PluginSettingsControllerTest extends TestCase
{
    private string $pluginsDir;
    private InstalledPluginsRegistry $installedPlugins;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-settings-controller-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);

        $this->installedPlugins = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function createController(
        SettingsPageRegistry $settingsPages,
        ?Environment $twig = null,
        ?LoggerInterface $logger = null,
    ): PluginSettingsController {
        return new PluginSettingsController(
            $this->installedPlugins,
            $settingsPages,
            $twig ?? $this->createStub(Environment::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    public function testInvokeRendersThePluginsPageInsideTheSettingsShell(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form>');

        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('animedb-shikimori', $params['pluginId']);
                self::assertSame('Shikimori', $params['pluginName']);
                self::assertSame('<form>settings</form>', $params['content']);
                self::assertFalse($params['renderFailed']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig);
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeRendersAnInlineErrorAndLogsWhenRenderThrows(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willThrowException(new \RuntimeException('API unreachable'));

        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['renderFailed'] === true && $params['content'] === null,
            ))
            ->willReturn('<html></html>');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $controller = $this->createController($settingsPages, $twig, $logger);
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeThrowsNotFoundForAMalformedPluginId(): void
    {
        $controller = $this->createController(new SettingsPageRegistry([], $this->installedPlugins));

        $this->expectException(NotFoundHttpException::class);
        $controller('Not A Valid Id');
    }

    public function testInvokeThrowsNotFoundWhenNoSettingsPageIsRegistered(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $controller = $this->createController(new SettingsPageRegistry([], $this->installedPlugins));

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori');
    }

    public function testInvokeThrowsNotFoundWhenThePluginIsDisabledEvenThoughAPageIsRegistered(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', json_encode([
            'animedb-shikimori' => ['enabled' => false],
        ]));
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $controller = $this->createController($settingsPages);

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori');
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
