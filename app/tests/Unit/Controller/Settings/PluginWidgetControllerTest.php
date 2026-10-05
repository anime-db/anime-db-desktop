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

use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Controller\Settings\PluginWidgetController;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Fixtures\Plugin\Widget\FakeEntryWidget;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PluginWidgetControllerTest extends TestCase
{
    private string $pluginsDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-widget-settings-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
        $this->configPath = $this->pluginsDir.'/plugins.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    private function installedPlugins(): InstalledPluginsRegistry
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->configPath), new NullLogger());
        $registry->reconcile();

        return $registry;
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

    private function alwaysValidCsrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    /**
     * Echoes the translation key back unchanged, i.e. simulates a plugin that hasn't shipped
     * this key's translation yet — the registry falls back to `widgetName` in that case.
     */
    private function noopTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    public function testIndexOrdersWidgetsByPluginInstallationOrderAndResolvesPluginNames(): void
    {
        // Widgets are registered zzz-plugin first, animedb-shikimori second — the opposite of the
        // order asserted below — to prove the rendered order follows
        // InstalledPluginsRegistry::all() (alphabetical by directory here), not DI/widget
        // registration order.
        $this->writeManifest('zzz-plugin', 'Zzz Plugin');
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $entryWidgets = new EntryWidgetRegistry(
            [
                'zzz-plugin:teaser' => new FakeEntryWidget(),
                'animedb-shikimori:related' => new FakeEntryWidget(),
            ],
            new PluginsConfigStore($this->configPath),
            $this->noopTranslator(),
        );
        $catalogWidgets = new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator());

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/plugin/widgets.html.twig', $this->callback(static function (array $params): bool {
                self::assertSame(
                    [
                        [
                            'pluginId' => 'animedb-shikimori',
                            'widgetName' => 'related',
                            'active' => false,
                            'slot' => 'bottom',
                            'title' => 'related',
                            'description' => 'related',
                            'pluginName' => 'Shikimori',
                        ],
                        [
                            'pluginId' => 'zzz-plugin',
                            'widgetName' => 'teaser',
                            'active' => false,
                            'slot' => 'bottom',
                            'title' => 'teaser',
                            'description' => 'teaser',
                            'pluginName' => 'Zzz Plugin',
                        ],
                    ],
                    $params['entryWidgets'],
                );
                self::assertSame(0, $params['entryActiveCount']);
                self::assertSame(5, $params['entryHardLimit']);
                self::assertSame(2, $params['catalogHardLimit']);
                self::assertSame(2, $params['recommendedLimit']);

                return true;
            }))
            ->willReturn('<html></html>');

        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            $entryWidgets,
            $catalogWidgets,
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $twig,
        );

        $response = $controller->index(Request::create('/settings/plugins/widgets'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testToggleEnablesAWidgetAndRedirectsToIndex(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class)],
            new PluginsConfigStore($this->configPath),
            $this->noopTranslator(),
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('settings_plugin_widgets_index')->willReturn('/settings/plugins/widgets');

        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            $entryWidgets,
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $response = $controller->toggle(
            'animedb-shikimori',
            'related',
            Request::create('/settings/plugins/widgets/animedb-shikimori/related', 'POST', ['placement' => 'entry', 'active' => '0']),
        );

        $this->assertSame('/settings/plugins/widgets', $response->getTargetUrl());
        $this->assertNull($entryWidgets->find(new PluginId('animedb-shikimori'), 'related'));
    }

    public function testToggleRedirectsWithHardLimitErrorWhenLimitIsExceeded(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5', 'w6'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = new FakeEntryWidget();
        }
        file_put_contents($this->configPath, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $entryWidgets = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->configPath), $this->noopTranslator());

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('settings_plugin_widgets_index', ['error' => 'hard_limit_exceeded', 'limit' => 5])
            ->willReturn('/settings/plugins/widgets?error=hard_limit_exceeded');

        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            $entryWidgets,
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $response = $controller->toggle(
            'animedb-shikimori',
            'w6',
            Request::create('/settings/plugins/widgets/animedb-shikimori/w6', 'POST', ['placement' => 'entry', 'active' => '1']),
        );

        $this->assertSame('/settings/plugins/widgets?error=hard_limit_exceeded', $response->getTargetUrl());
    }

    /**
     * The lock acquire is non-blocking with a short bounded retry (issue #340): if the settings
     * page's own toggle() loses the race for the lock, it must redirect with a friendly
     * "busy, retry" error rather than let {@see PluginsConfigStoreLockedException} bubble up as
     * an uncaught 500 — precisely the "disable that stuck plugin" scenario the fail-fast lock was
     * introduced for.
     */
    public function testToggleRedirectsWithBusyRetryErrorWhenTheLockIsHeldByAnotherWriter(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');

        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class)],
            new PluginsConfigStore($this->configPath),
            $this->noopTranslator(),
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('settings_plugin_widgets_index', ['error' => 'busy_retry'])
            ->willReturn('/settings/plugins/widgets?error=busy_retry');

        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            $entryWidgets,
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $this->alwaysValidCsrf(),
            $urlGenerator,
            $this->createStub(Environment::class),
        );

        $lockHandle = fopen($this->configPath.'.lock', 'c');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX));

        try {
            $response = $controller->toggle(
                'animedb-shikimori',
                'related',
                Request::create('/settings/plugins/widgets/animedb-shikimori/related', 'POST', ['placement' => 'entry', 'active' => '1']),
            );

            $this->assertSame('/settings/plugins/widgets?error=busy_retry', $response->getTargetUrl());
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function testToggleThrowsBadRequestForAnUnknownPlacement(): void
    {
        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            new EntryWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->toggle(
            'animedb-shikimori',
            'related',
            Request::create('/settings/plugins/widgets/animedb-shikimori/related', 'POST', ['placement' => 'unknown', 'active' => '1']),
        );
    }

    public function testToggleThrowsNotFoundForAMalformedPluginId(): void
    {
        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            new EntryWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->toggle(
            'Not A Valid Id',
            'related',
            Request::create('/settings/plugins/widgets/Not A Valid Id/related', 'POST', ['placement' => 'entry', 'active' => '1']),
        );
    }

    public function testToggleThrowsBadRequestForAnInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = new PluginWidgetController(
            $this->installedPlugins(),
            new EntryWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $csrf,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Environment::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->toggle(
            'animedb-shikimori',
            'related',
            Request::create('/settings/plugins/widgets/animedb-shikimori/related', 'POST', ['placement' => 'entry', 'active' => '1']),
        );
    }

    public function testSlotStoresTheChosenSlotAndRedirectsToIndex(): void
    {
        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class)],
            new PluginsConfigStore($this->configPath),
            $this->noopTranslator(),
        );
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/plugins/widgets');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $token->getId() === 'settings_plugin_widgets_slot_animedb-shikimori_related'
                && $token->getValue() === 'valid-token',
        );
        $controller = $this->slotController($entryWidgets, $csrf, $urlGenerator);

        $response = $controller->slot('animedb-shikimori', 'related', Request::create('/x', 'POST', ['slot' => 'side', '_token' => 'valid-token']));

        $this->assertSame('/settings/plugins/widgets', $response->getTargetUrl());
        $stored = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('side', $stored['animedb-shikimori']['widget_slot']['related']);
    }

    public function testSlotThrowsBadRequestForAnUnknownSlotWithoutWriting(): void
    {
        $controller = $this->slotController(
            new EntryWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $this->alwaysValidCsrf(),
            $this->createStub(UrlGeneratorInterface::class),
        );

        try {
            $controller->slot('animedb-shikimori', 'related', Request::create('/x', 'POST', ['slot' => 'top']));
            $this->fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            $this->assertFileDoesNotExist($this->configPath);
        }
    }

    public function testSlotThrowsBadRequestForAnInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);
        $controller = $this->slotController(
            new EntryWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $csrf,
            $this->createStub(UrlGeneratorInterface::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->slot('animedb-shikimori', 'related', Request::create('/x', 'POST', ['slot' => 'side']));
    }

    private function slotController(EntryWidgetRegistry $entryWidgets, CsrfTokenManagerInterface $csrf, UrlGeneratorInterface $urlGenerator): PluginWidgetController
    {
        return new PluginWidgetController(
            $this->installedPlugins(),
            $entryWidgets,
            new CatalogWidgetRegistry([], new PluginsConfigStore($this->configPath), $this->noopTranslator()),
            $csrf,
            $urlGenerator,
            $this->createStub(Environment::class),
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
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
