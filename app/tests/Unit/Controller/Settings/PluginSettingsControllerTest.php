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
use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Controller\Settings\PluginSettingsController;
use App\Message\BackfillExternalIdMessage;
use App\Message\SyncSeedMessage;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginAssetResolver;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PluginUiAssetsResolver;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\SyncRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        ?SyncRegistry $syncRegistry = null,
        ?PluginsConfigStore $pluginsConfigStore = null,
        ?MessageBusInterface $messageBus = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?PluginUiAssetsResolver $pluginUiAssets = null,
    ): PluginSettingsController {
        return new PluginSettingsController(
            $this->installedPlugins,
            $settingsPages,
            $syncRegistry ?? new SyncRegistry([], new PluginsConfigStore($this->pluginsDir.'/plugins.json')),
            $pluginsConfigStore ?? new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
            $twig ?? $this->createStub(Environment::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            $pluginUiAssets ?? $this->createPluginUiAssetsResolver(),
        );
    }

    private function createPluginUiAssetsResolver(): PluginUiAssetsResolver
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params): string => \sprintf(
                '/plugin/%s/asset/%s/%s',
                $params['pluginId'],
                $params['fingerprint'],
                $params['path'],
            ),
        );

        return new PluginUiAssetsResolver(new PluginAssetResolver($this->installedPlugins), $urlGenerator, new NullLogger());
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

    public function testInvokePassesThePluginsDeclaredUiToTheTemplate(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir.'/assets', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            'ui' => ['css' => ['assets/carousel.css'], 'js' => []],
        ]));
        file_put_contents($dir.'/assets/carousel.css', '.carousel {}');
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $page->method('render')->willReturn('<form>settings</form>');
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(static function (array $params): bool {
                return \count($params['pluginUi']['css']) === 1
                    && str_ends_with($params['pluginUi']['css'][0], '/assets/carousel.css')
                    && $params['pluginUi']['js'] === [];
            }))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig);
        $controller('animedb-shikimori');
    }

    public function testInvokeRendersThePluginsPageWhenADeclaredUiAssetFileIsMissing(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir.'/assets', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            'ui' => ['css' => ['assets/missing.css'], 'js' => []],
        ]));
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $page->method('render')->willReturn('<form>settings</form>');
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['pluginUi'] === ['css' => [], 'js' => []],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig);
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeDispatchesSyncSeedAndBackfillExternalIdAndRedirectsWhenThePluginIsAnActiveSyncPlugin(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));
        $this->installedPlugins->reconcile();

        $sync = $this->createStub(SyncInterface::class);
        $syncRegistry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
        );

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->never())->method('render');
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $dispatched = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('settings_sync_review_index')
            ->willReturn('/settings/sync-review');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->never())->method('render');

        $controller = $this->createController(
            $settingsPages,
            twig: $twig,
            syncRegistry: $syncRegistry,
            messageBus: $messageBus,
            urlGenerator: $urlGenerator,
        );
        $response = $controller('animedb-shikimori');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/sync-review', $response->getTargetUrl());

        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(SyncSeedMessage::class, $dispatched[0]);
        $this->assertSame('animedb-shikimori', $dispatched[0]->pluginId);
        $this->assertInstanceOf(BackfillExternalIdMessage::class, $dispatched[1]);
        $this->assertSame('animedb-shikimori', $dispatched[1]->pluginId);
    }

    public function testInvokeOnlyDispatchesSyncSeedAndBackfillExternalIdOnceAcrossRepeatedVisits(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));
        $this->installedPlugins->reconcile();

        $sync = $this->createStub(SyncInterface::class);
        $pluginsConfigStore = new PluginsConfigStore($this->pluginsDir.'/plugins.json');
        $syncRegistry = new SyncRegistry(['animedb-shikimori' => $sync], $pluginsConfigStore);

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form>');
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/sync-review');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->willReturn('<html></html>');

        $controller = $this->createController(
            $settingsPages,
            twig: $twig,
            syncRegistry: $syncRegistry,
            pluginsConfigStore: $pluginsConfigStore,
            messageBus: $messageBus,
            urlGenerator: $urlGenerator,
        );

        $first = $controller('animedb-shikimori');
        $second = $controller('animedb-shikimori');

        $this->assertInstanceOf(RedirectResponse::class, $first);
        $this->assertSame(200, $second->getStatusCode());
    }

    public function testInvokeRendersThePluginsPageInsteadOfReSeedingWhenAlreadySeeded(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true], 'syncSeeded' => true],
        ]));
        $this->installedPlugins->reconcile();

        $sync = $this->createStub(SyncInterface::class);
        $syncRegistry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
        );

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form>');
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->willReturn('<html></html>');

        $controller = $this->createController(
            $settingsPages,
            twig: $twig,
            syncRegistry: $syncRegistry,
            messageBus: $messageBus,
        );
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeRendersThePluginsPageInsteadOf500WhenTheConfigStoreLockIsExhausted(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));
        $this->installedPlugins->reconcile();

        $sync = $this->createStub(SyncInterface::class);
        $syncRegistry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
        );

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form>');
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->willReturn('<html></html>');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $logger->expects($this->never())->method('error');

        // A file lock opened via a separate handle in the same process still contends with
        // itself (flock() locks belong to the open file description, not the process), so this
        // reliably starves PluginsConfigStore::acquireLock() into PluginsConfigStoreLockedException
        // without needing a second process or thread.
        $lockHandle = fopen($this->pluginsDir.'/plugins.json.lock', 'c');
        \assert($lockHandle !== false);
        flock($lockHandle, \LOCK_EX);

        try {
            $controller = $this->createController(
                $settingsPages,
                twig: $twig,
                logger: $logger,
                syncRegistry: $syncRegistry,
                messageBus: $messageBus,
            );
            $response = $controller('animedb-shikimori');
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }

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

    public function testInvokeRendersTheInlineErrorFallbackWhenRenderThrowsAndADeclaredUiAssetFileIsMissing(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir.'/assets', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            'ui' => ['css' => ['assets/missing.css'], 'js' => []],
        ]));
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willThrowException(new \RuntimeException('API unreachable'));
        $settingsPages = new SettingsPageRegistry(['animedb-shikimori' => $page], $this->installedPlugins);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['renderFailed'] === true
                    && $params['pluginUi'] === ['css' => [], 'js' => []],
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
