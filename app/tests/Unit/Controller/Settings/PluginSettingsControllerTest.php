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
use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginAssetResolver;
use App\Service\Plugin\PluginHtmlSanitizer;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PluginUiAssetsResolver;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\SyncRegistry;
use App\Service\Plugin\SyncSeedDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
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
        string $oauthCallbackFixedPort = '1',
    ): PluginSettingsController {
        return new PluginSettingsController(
            $this->installedPlugins,
            $settingsPages,
            $syncRegistry ?? new SyncRegistry([], new PluginsConfigStore($this->pluginsDir.'/plugins.json')),
            new SyncSeedDispatcher(
                $pluginsConfigStore ?? new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
                $messageBus ?? $this->createStub(MessageBusInterface::class),
                $logger ?? $this->createStub(LoggerInterface::class),
            ),
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
            $twig ?? $this->createStub(Environment::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            $pluginUiAssets ?? $this->createPluginUiAssetsResolver(),
            new PluginHtmlSanitizer(),
            $oauthCallbackFixedPort,
        );
    }

    /** @param array<string, SettingsPageInterface> $pages */
    private function settingsPages(array $pages = []): SettingsPageRegistry
    {
        $locator = new ServiceLocator(array_map(
            static fn (SettingsPageInterface $page): \Closure => static fn (): SettingsPageInterface => $page,
            $pages,
        ));

        return new SettingsPageRegistry($this->installedPlugins, $locator);
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

    /**
     * Acceptance (issue #825): opening plugin B's own settings page must not touch plugin A's
     * settings-page service at all, even when A's constructor throws — {@see SettingsPageRegistry::find()}
     * only ever resolves the one plugin id it was asked for via the locator.
     */
    public function testInvokeRendersThePluginsPageEvenWhenAnotherPluginsSettingsPageConstructorThrows(): void
    {
        $this->writeManifest('animedb-broken', 'Broken');
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $workingPage = $this->createMock(SettingsPageInterface::class);
        $workingPage->expects($this->once())->method('render')->willReturn('<form>settings</form>');

        $locator = new ServiceLocator([
            'animedb-broken' => static fn (): SettingsPageInterface => throw new \RuntimeException('Must not be instantiated when opening a different plugin\'s settings page.'),
            'animedb-shikimori' => static fn (): SettingsPageInterface => $workingPage,
        ]);
        $settingsPages = new SettingsPageRegistry($this->installedPlugins, $locator);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['content'] === '<form>settings</form>' && $params['renderFailed'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig);
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeRendersThePluginsPageInsideTheSettingsShell(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form>');

        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

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

    public function testInvokeSanitizesScriptTagOutOfThePluginsOwnSettingsMarkup(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form><script>alert(1)</script>');

        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(static function (array $params): bool {
                self::assertIsString($params['content']);
                self::assertStringNotContainsString('<script', $params['content']);
                self::assertStringContainsString('<form>settings</form>', $params['content']);

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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

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

    /**
     * Issue #865: the first visit to an active sync plugin's settings page must render that page
     * (not redirect) so the plugin's own OAuth button stays reachable even when `features.sync`
     * was switched on before OAuth completed — the seed is still queued, just with a notice above
     * the plugin's markup instead of a redirect away from it.
     */
    public function testInvokeDispatchesSyncSeedOnceAndRendersThePluginsPageWithANoticeOnFirstVisit(): void
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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $dispatched = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
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
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame('<form>settings</form>', $params['content']);
                self::assertFalse($params['renderFailed']);
                self::assertSame('/settings/sync-review', $params['syncReviewUrl']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = $this->createController(
            $settingsPages,
            twig: $twig,
            syncRegistry: $syncRegistry,
            messageBus: $messageBus,
            urlGenerator: $urlGenerator,
        );
        $response = $controller('animedb-shikimori');

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        // Issue #867: the external-id backfill runs inside SyncSeedMessageHandler, so the first
        // visit queues exactly one message and it is the seed.
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(SyncSeedMessage::class, $dispatched[0]);
        $this->assertSame('animedb-shikimori', $dispatched[0]->pluginId);
    }

    public function testInvokeOnlyDispatchesSyncSeedOnceAcrossRepeatedVisits(): void
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
        $page->expects($this->exactly(2))->method('render')->willReturn('<form>settings</form>');
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/sync-review');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->exactly(2))->method('render')->willReturn('<html></html>');

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

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
    }

    /**
     * Issue #865 acceptance (2): a repeated visit after `syncSeeded` is already `true` renders the
     * plugin's page with no further dispatch and no notice — the notice is only for the visit that
     * actually queued the seed.
     */
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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->never())->method('generate');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['syncReviewUrl'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(
            $settingsPages,
            twig: $twig,
            syncRegistry: $syncRegistry,
            messageBus: $messageBus,
            urlGenerator: $urlGenerator,
        );
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Issue #865 acceptance (3): the loop scenario. The first visit queues the seed as usual; then
     * {@see \App\MessageHandler\SyncSeedMessageHandler} resets `syncSeeded` back to `false` because
     * `pull()` stopped short on missing OAuth (exactly what it does on a `false` return). The next
     * visit must still render 200 with the plugin's own markup — where the authorize button lives —
     * re-queue the seed, and show the notice again, instead of looping on a redirect forever.
     */
    public function testInvokeReQueuesTheSeedAndShowsTheNoticeAgainAfterTheHandlerResetSyncSeeded(): void
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
        $page->expects($this->exactly(2))->method('render')->willReturn('<form>settings</form>');
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/sync-review');

        $twig = $this->createMock(Environment::class);
        $renderedParams = [];
        $twig->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(static function (string $template, array $params) use (&$renderedParams): string {
                $renderedParams[] = $params;

                return '<html></html>';
            });

        $controller = $this->createController(
            $settingsPages,
            twig: $twig,
            syncRegistry: $syncRegistry,
            pluginsConfigStore: $pluginsConfigStore,
            messageBus: $messageBus,
            urlGenerator: $urlGenerator,
        );

        $first = $controller('animedb-shikimori');

        // Simulate SyncSeedMessageHandler resetting the flag after pull() reports it stopped
        // short on missing OAuth — the same write SyncSeedMessageHandler::__invoke() performs.
        $pluginsConfigStore->updatePluginSettings(new PluginId('animedb-shikimori'), static function (array $settings): array {
            $settings['syncSeeded'] = false;

            return $settings;
        });

        $second = $controller('animedb-shikimori');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertNotNull($renderedParams[0]['syncReviewUrl']);
        $this->assertNotNull($renderedParams[1]['syncReviewUrl']);
    }

    /**
     * Issue #865 acceptance (4): lock exhaustion still degrades to a normal render with the seed
     * skipped for this visit — unchanged from before this issue, just no longer reachable via a
     * redirect branch.
     */
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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['syncReviewUrl'] === null,
            ))
            ->willReturn('<html></html>');

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

    /**
     * Issue #865 acceptance (5): a plugin with no {@see SyncInterface} entry in {@see SyncRegistry}
     * never enters the connect-seed branch at all — no dispatch, no notice.
     */
    public function testInvokeRendersThePluginsPageWithNoSeedAndNoNoticeWhenThePluginIsNotASyncPlugin(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willReturn('<form>settings</form>');
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['syncReviewUrl'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, twig: $twig, messageBus: $messageBus);
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeRendersAnInlineErrorAndLogsWhenRenderThrows(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willThrowException(new \RuntimeException('API unreachable'));

        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

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

    /**
     * Issue #871: OAUTH_CALLBACK_FIXED_PORT=0 means the host's fixed-port OAuth-redirect listener
     * could not bind (native/supervisor/oauth-callback.js) — the shell must warn on both render
     * branches, not just the happy path.
     */
    public function testInvokeShowsTheOauthFixedPortWarningWhenTheFixedPortFellBack(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $page->method('render')->willReturn('<form>settings</form>');
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['oauthCallbackWarning'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig, oauthCallbackFixedPort: '0');
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeShowsTheOauthFixedPortWarningOnTheRenderFailedBranchToo(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createMock(SettingsPageInterface::class);
        $page->expects($this->once())->method('render')->willThrowException(new \RuntimeException('API unreachable'));
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['renderFailed'] === true && $params['oauthCallbackWarning'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig, logger: $this->createStub(LoggerInterface::class), oauthCallbackFixedPort: '0');
        $response = $controller('animedb-shikimori');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInvokeDoesNotShowTheOauthFixedPortWarningWhenTheFixedPortBound(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $page = $this->createStub(SettingsPageInterface::class);
        $page->method('render')->willReturn('<form>settings</form>');
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/page.html.twig', $this->callback(
                static fn (array $params): bool => $params['oauthCallbackWarning'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController($settingsPages, $twig, oauthCallbackFixedPort: '1');
        $controller('animedb-shikimori');
    }

    public function testInvokeThrowsNotFoundForAMalformedPluginId(): void
    {
        $controller = $this->createController($this->settingsPages());

        $this->expectException(NotFoundHttpException::class);
        $controller('Not A Valid Id');
    }

    public function testInvokeThrowsNotFoundWhenNoSettingsPageIsRegistered(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        $this->installedPlugins->reconcile();

        $controller = $this->createController($this->settingsPages());

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
        $settingsPages = $this->settingsPages(['animedb-shikimori' => $page]);

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
