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

namespace App\Tests\Unit\Translation;

use App\EventSubscriber\LocaleSubscriber;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Acceptance (issue #538, "Чем это грозит" #2): an `integration` plugin's own domain
 * (`<plugin-id>.<locale>.yaml`) exists only inside that plugin — core does not mix anything into
 * it, unlike the shared `messages` domain. A plugin that ships only a `ru` catalogue for its own
 * domain must still resolve for a `kk` request through the locale-dependent fallback chain
 * {@see LocaleSubscriber} now sets, the same way {@see \App\Tests\Unit\Controller\TranslationControllerPluginLocaleFallbackTest}
 * already proves it for the static `[en]` chain.
 */
final class PluginDomainNearestLocaleFallbackTest extends KernelTestCase
{
    private string $pluginsDir;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;

    protected function setUp(): void
    {
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;

        $runtimeDir = sys_get_temp_dir().'/anime-plugin-domain-fallback-test-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-domain-fallback-test-plugins-'.uniqid();
        mkdir($runtimeDir, recursive: true);
        mkdir($this->pluginsDir, recursive: true);

        $_SERVER['APP_RUNTIME_DIR'] = $runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreServerVar('APP_RUNTIME_DIR', $this->originalRuntimeDir);
        $this->restoreServerVar('PLUGINS_DIR', $this->originalPluginsDir);
        $this->restoreServerVar('PLUGINS_CONFIG_PATH', $this->originalPluginsConfigPath);
    }

    public function testPluginDomainKeyOnlyDefinedInRussianResolvesForKazakhRequestViaTheNearestLocale(): void
    {
        $pluginId = 'acme-widgets';
        $this->writeIntegrationPluginFixture($pluginId);
        $this->reconcilePlugins();

        $kernel = self::bootKernel();

        // Simulates the real kernel.request dispatch a "kk" browser/OS locale would trigger,
        // exercising the production LocaleSubscriber rather than calling setFallbackLocales()
        // directly, so this test would fail if the wiring in LocaleSubscriber ever regressed.
        /** @var LocaleSubscriber $localeSubscriber */
        $localeSubscriber = self::getContainer()->get(LocaleSubscriber::class);

        $request = Request::create('/');
        $request->headers->set('Accept-Language', 'kk');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $localeSubscriber->onKernelRequest($event);

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame(
            'Виджет плагина',
            $translator->trans('widget.title', domain: $pluginId, locale: 'kk'),
            'A "kk" request must resolve a plugin-domain key that only exists in the plugin\'s "ru" catalogue, not fall through to the raw key id.',
        );
    }

    private function writeIntegrationPluginFixture(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        // No en/kk catalogue at all for this plugin's own domain — only ru, on purpose.
        file_put_contents($dir.'/translations/'.$pluginId.'.ru.yaml', "widget:\n    title: 'Виджет плагина'\n");
    }

    private function reconcilePlugins(): void
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();
    }

    private function restoreServerVar(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original;
        }
    }
}
