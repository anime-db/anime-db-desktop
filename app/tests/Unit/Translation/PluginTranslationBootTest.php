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

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * End-to-end coverage for the plugin translation catalog path (issue #451):
 * {@see \App\Service\Plugin\PluginLoader::translationPaths()} feeding
 * {@see \App\Kernel::configureContainer()}'s `framework.translator.paths`, exercised through a
 * real, cold-compiled container the same way {@see \App\Kernel} boots in production — unlike
 * {@see \App\Tests\Unit\Service\Plugin\PluginLoaderTest}, which only checks the path list
 * `translationPaths()` returns, never that Symfony's Translator actually resolves anything from
 * it.
 *
 * A cold compile is required, not incidental: {@see InstalledPluginsRegistry}'s index
 * (`installed-plugins.php`) is read once at container-build time via
 * {@see \App\Kernel::configureContainer()}, and container freshness tracks neither that index
 * nor `plugins.json` (issue #451 "Деталь 1") — reusing a pre-existing compiled container (e.g.
 * the repo's own `var/cache/test/`) would report a green test that never actually reads the
 * fixture plugins below. `APP_RUNTIME_DIR`/`PLUGINS_DIR`/`PLUGINS_CONFIG_PATH` are pointed at
 * fresh temporary directories per test run for exactly this reason, and restored in
 * {@see self::tearDown()} — PHPUnit runs this whole suite in one process, so a later test class
 * would otherwise inherit these overrides.
 *
 * A cold compile with an installed plugin used to fatal unconditionally (issue #458: three
 * compiler passes called `class_exists()` on service class names before checking whether they
 * belonged to a plugin namespace at all, and tripped over `doctrine.orm.validator.unique`'s
 * class chain, which references `symfony/validator` — a package this app does not require).
 * Fixed by #459 (commit ff5ecf9): each pass now resolves the owning plugin id first and skips
 * non-plugin classes before ever calling `class_exists()`. This test is what proves that stays
 * fixed for the plugin-translation path specifically.
 */
final class PluginTranslationBootTest extends KernelTestCase
{
    private string $runtimeDir;
    private string $pluginsDir;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;
    private ?string $originalCoreVersion;

    protected function setUp(): void
    {
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;
        $this->originalCoreVersion = $_SERVER['CORE_VERSION'] ?? null;

        $this->runtimeDir = sys_get_temp_dir().'/anime-plugin-translation-boot-test-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-translation-boot-test-plugins-'.uniqid();
        mkdir($this->runtimeDir, recursive: true);
        mkdir($this->pluginsDir, recursive: true);

        $_SERVER['APP_RUNTIME_DIR'] = $this->runtimeDir;
        $_SERVER['PLUGINS_DIR'] = $this->pluginsDir;
        $_SERVER['PLUGINS_CONFIG_PATH'] = $this->pluginsDir.'/plugins.json';
        // Mocks the same Electron-supplied channel native/supervisor/env.js sets in production
        // (issue #565) — without it, Kernel::coreVersion() would fall back to this checkout's own
        // package.json version, which does not satisfy the fixture manifests' `require.core`
        // below and would keep their plugin bundles from registering at all.
        $_SERVER['CORE_VERSION'] = '2.0.0';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreServerVar('APP_RUNTIME_DIR', $this->originalRuntimeDir);
        $this->restoreServerVar('PLUGINS_DIR', $this->originalPluginsDir);
        $this->restoreServerVar('PLUGINS_CONFIG_PATH', $this->originalPluginsConfigPath);
        $this->restoreServerVar('CORE_VERSION', $this->originalCoreVersion);

        $this->removeDirectory($this->runtimeDir);
        $this->removeDirectory($this->pluginsDir);
    }

    public function testTranslatorAppliesPluginCatalogsWithCorePriorityAndOwnDomainSupport(): void
    {
        $translationPluginId = 'acme-i18n-pack';
        $this->writeTranslationPluginFixture($translationPluginId);

        $integrationPluginId = 'acme-widgets';
        $this->writeIntegrationPluginFixture($integrationPluginId);

        $this->reconcilePlugins();

        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        // Positive control (issue #451 "Деталь 2"): a plugin catalog only "counts" once it is
        // proven to have actually reached the Translator. This assertion MUST run, and pass,
        // before the non-override assertion below — otherwise a green non-override check would
        // be meaningless, since it would pass identically if the plugin's translations/
        // directory was never wired into framework.translator.paths at all.
        self::assertSame(
            'Значение плагина',
            $translator->trans($translationPluginId.'.probe', locale: 'ru'),
            'Plugin catalog directory did not reach the Translator via framework.translator.paths.',
        );

        // Priority granularity, by (domain x locale x key) (issue #451 "Деталь 3"): in the
        // `messages` domain, a core string always wins over a plugin that declares the very
        // same locale + key — even though that same plugin's OWN additional key in that same
        // domain/locale does apply, as proven immediately above.
        //
        // THIS IS A DRIFT DETECTOR, NOT A PRIORITY GATE (issue #451 "Деталь 4"). Nothing in the
        // production code enforces this guarantee on purpose; it falls out, unowned, of the
        // order Kernel::configureContainer() feeds framework.translator.paths in: plugin
        // directories from PluginLoader::translationPaths() are added first, and
        // config/packages/framework.yaml's `default_path` (app/translations, the core catalog)
        // is appended by FrameworkExtension after them, unconditionally, always last — Symfony's
        // translator resolves a (domain, locale, key) collision in favour of the last-added
        // resource. If this assertion goes red, a Symfony upgrade (or a translator config
        // change) reordered `paths`/`default_path`. DO NOT "fix" this test by updating the
        // expected value to whatever the plugin now produces — that would silently let an
        // installed language pack overwrite core strings, including confirmation text for
        // destructive actions. Instead: make the priority an explicit, owned rule (e.g.
        // compile-time validation rejecting a plugin catalog that redeclares a core `messages`
        // key) before deciding the new order is even acceptable.
        self::assertSame(
            'Романтика',
            $translator->trans('genre.romance', locale: 'ru'),
            'A plugin overrode a core "messages" string — see the drift-detector comment above.',
        );

        // Regression (issue #451 criteria, issue #373): an `integration` plugin's OWN domain
        // still resolves correctly in both core locales, independently of the `messages`-domain
        // priority guarantee checked above.
        self::assertSame(
            'Виджет плагина',
            $translator->trans('widget.title', locale: 'ru', domain: $integrationPluginId),
        );
        self::assertSame(
            'Plugin widget',
            $translator->trans('widget.title', locale: 'en', domain: $integrationPluginId),
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
            'locales' => ['ru'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        // `genre.romance` already exists in the core `messages.ru.yaml` catalog — this plugin
        // tries to redeclare it, and must lose (see the drift-detector comment in the test
        // method). `{$pluginId}.probe` does not exist in core at all — the positive control.
        file_put_contents(
            $dir.'/translations/messages.ru.yaml',
            $pluginId.".probe: 'Значение плагина'\ngenre.romance: 'ПОДМЕНЕНО ПЛАГИНОМ'\n",
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

        // Filename-derived domain, by convention the plugin id (issue #373) — no src/ directory
        // on purpose, this fixture only needs its translations/ wired up, not a bundle.
        file_put_contents($dir.'/translations/'.$pluginId.'.ru.yaml', "widget:\n    title: 'Виджет плагина'\n");
        file_put_contents($dir.'/translations/'.$pluginId.'.en.yaml', "widget:\n    title: 'Plugin widget'\n");
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
