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

namespace App\Tests\Unit\Controller;

use App\Controller\TranslationController;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end coverage for the fallback merge (issue #516): a locale the app itself does not
 * ship — one contributed only by an installed "translation" plugin, per
 * {@see \App\Service\Plugin\AvailableLocalesProvider} — must still resolve keys that locale's own
 * catalogue does not define, by falling back to the core `en` catalogue. Twig already gets this
 * for free from `framework.translator.fallbacks`; the JSON catalogue this controller serves did
 * not, because `MessageCatalogue::all()` only reads a catalogue's own messages and ignores the
 * fallback chain entirely.
 *
 * A cold kernel boot is required, not a mocked `TranslatorBagInterface`, to prove the merge holds
 * against Symfony's *real* fallback catalogue wiring (`framework.translator.fallbacks: [en]`) —
 * see {@see \App\Tests\Unit\Translation\PluginTranslationBootTest} for why a pre-existing compiled
 * container would silently skip the plugin fixture below.
 */
final class TranslationControllerPluginLocaleFallbackTest extends KernelTestCase
{
    use TemporaryDirectories;

    private string $pluginsDir;
    private ?string $originalRuntimeDir;
    private ?string $originalPluginsDir;
    private ?string $originalPluginsConfigPath;

    protected function setUp(): void
    {
        $this->originalRuntimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $this->originalPluginsDir = $_SERVER['PLUGINS_DIR'] ?? null;
        $this->originalPluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? null;

        $runtimeDir = $this->createTemporaryDirectory('anime-translation-fallback-test-runtime-');
        $this->pluginsDir = $this->createTemporaryDirectory('anime-translation-fallback-test-plugins-');

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

        $this->removeTemporaryDirectories();
    }

    public function testInvokeFillsMissingKeysFromTheEnglishFallbackForAPluginOnlyLocale(): void
    {
        $pluginId = 'acme-de-pack';
        $this->writeTranslationPluginFixture($pluginId);
        $this->reconcilePlugins();

        self::bootKernel();

        /** @var TranslationController $controller */
        $controller = self::getContainer()->get(TranslationController::class);
        $response = $controller('de');

        $catalogue = json_decode((string) $response->getContent(), true);

        // The plugin's own `de` catalogue defines this key — it must win over the `en` fallback.
        self::assertSame('Romantik', $catalogue['genre.romance']);

        // The plugin's `de` catalogue does not define this key at all — it must still be present,
        // filled in from the core `en` catalogue, exactly like Twig's `trans()` already does.
        self::assertSame('Action', $catalogue['genre.action']);
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
            'locales' => ['de'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        // `genre.romance` also exists in the core `messages.en.yaml` catalogue — this fixture
        // redeclares it to prove the plugin's own string still wins over the fallback. Every
        // other core key (e.g. `genre.action`) is left undefined here on purpose.
        file_put_contents($dir.'/translations/messages.de.yaml', "genre:\n    romance: 'Romantik'\n");
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
