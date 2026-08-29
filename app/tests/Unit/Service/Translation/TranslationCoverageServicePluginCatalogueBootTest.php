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

namespace App\Tests\Unit\Service\Translation;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\TranslationCoverageService;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Proves, on a real cold-compiled container, that {@see TranslationCoverageService}'s reference key
 * set is the app's own `translations/messages.en.yaml` file — never the Symfony Translator's
 * compiled `messages` catalogue, into which {@see \App\Kernel::configureContainer()} already merges
 * every enabled translation plugin's own `translations/` directory via
 * {@see \App\Service\Plugin\PluginLoader::translationPaths()} (see
 * {@see \App\Tests\Unit\Translation\PluginTranslationBootTest} for that wiring itself).
 *
 * A plain unit test constructing {@see TranslationCoverageService} directly cannot exercise this:
 * the service takes `$projectDir` as a string and never touches the Translator, so no merged
 * catalogue exists in that setup for a future "just read it from the Translator" simplification to
 * collide with. Only a real container — where the plugin's catalog has actually reached
 * `framework.translator.paths` — can fail this test if that simplification were ever made.
 */
final class TranslationCoverageServicePluginCatalogueBootTest extends KernelTestCase
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

        $this->runtimeDir = sys_get_temp_dir().'/anime-translation-coverage-boot-runtime-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/anime-translation-coverage-boot-plugins-'.uniqid();
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

    public function testAPluginOnlyKeyStaysOrphanedEvenThoughTheTranslatorMergedItIntoTheMessagesCatalogue(): void
    {
        $pluginId = 'acme-coverage-probe';
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations', recursive: true);

        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => 'Coverage probe',
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['en'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        // 'coverage_probe.plugin_only' exists nowhere in the app's own catalogue.
        file_put_contents(
            $dir.'/translations/messages.en.yaml',
            "coverage_probe:\n    plugin_only: 'Only the plugin has this'\n",
        );

        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        self::bootKernel();

        // Positive control: the plugin's catalog did reach the Translator's compiled 'messages'
        // catalogue for 'en'. If this assertion fails, the negative assertion below would pass for
        // the wrong reason (the key never merged in at all), making it meaningless.
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame(
            'Only the plugin has this',
            $translator->trans('coverage_probe.plugin_only', locale: 'en'),
            'Plugin catalog directory did not reach the Translator via framework.translator.paths.',
        );

        // The reference key set TranslationCoverageService compares against still does not contain
        // 'coverage_probe.plugin_only' — it stays an orphan of the plugin's own catalog, not a
        // covered key, even though the Translator's merged catalogue already resolves it above.
        /** @var TranslationCoverageService $coverageService */
        $coverageService = self::getContainer()->get(TranslationCoverageService::class);
        $report = $coverageService->coverageForInstalledPlugin(new PluginId($pluginId));

        self::assertNotNull($report);
        self::assertContains('coverage_probe.plugin_only', $report->coverage['en']->orphans);
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
