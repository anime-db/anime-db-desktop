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

namespace App\Tests\Acceptance;

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A `translation`-type plugin manifest is free to declare a locale with a region or script
 * subtag (`ManifestValidator::validateLocales()` only requires a non-empty string), and its
 * catalog file is registered by that exact file name (`messages.pt-BR.yaml` -> catalog `pt-BR`,
 * see `Kernel::configureContainer()`). `Request::getPreferredLanguage()` runs the negotiated
 * result through Symfony's private `formatLocale()`, which would otherwise rewrite it to
 * `pt_BR` — a catalog name that does not exist — and silently fall through to English (issue
 * #557). This drives a real request through the kernel so it fails the same way that bug did if
 * {@see \App\EventSubscriber\LocaleSubscriber}'s spelling restore ever regressed, not just at the
 * unit level.
 */
final class PluginLocaleSpellingNormalizationTest extends KernelTestCase
{
    private string $runtimeDir;
    private string $pluginsDir;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;

    protected function setUp(): void
    {
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;

        $this->runtimeDir = sys_get_temp_dir().'/anime-plugin-locale-spelling-test-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-locale-spelling-test-plugins-'.uniqid();
        mkdir($this->runtimeDir, recursive: true);
        mkdir($this->pluginsDir, recursive: true);

        $_SERVER['APP_RUNTIME_DIR'] = $this->runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreServerVar('APP_RUNTIME_DIR', $this->originalRuntimeDir);
        $this->restoreServerVar('PLUGINS_DIR', $this->originalPluginsDir);
        $this->restoreServerVar('PLUGINS_CONFIG_PATH', $this->originalPluginsConfigPath);

        $this->removeDirectory($this->runtimeDir);
        $this->removeDirectory($this->pluginsDir);
    }

    public function testRequestAndTranslatorReceiveThePluginDeclaredHyphenatedLocaleSpelling(): void
    {
        $pluginId = 'acme-pt-br-pack';
        $this->writeTranslationPluginFixture($pluginId);
        $this->reconcilePlugins();

        $kernel = self::bootKernel();

        $request = Request::create('/settings/proxy');
        $request->headers->set('Accept-Language', 'pt-BR');

        $response = $kernel->handle($request);

        $body = (string) $response->getContent();

        self::assertStringContainsString(
            '<h1>Servidor proxy</h1>',
            $body,
            'The plugin-provided "pt-BR" catalog did not resolve — the negotiated locale likely reached the translator as "pt_BR".',
        );
        self::assertStringContainsString(
            '<html lang="pt-BR"',
            $body,
            'The <html lang> attribute must keep the hyphenated spelling declared by the plugin, not Symfony\'s underscore form.',
        );
    }

    private function writeTranslationPluginFixture(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['pt-BR'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        file_put_contents(
            $dir.'/translations/messages.pt-BR.yaml',
            "settings_proxy:\n    heading: 'Servidor proxy'\n",
        );
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
