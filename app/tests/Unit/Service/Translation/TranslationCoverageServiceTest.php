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

use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\PluginTranslationReport;
use App\Service\Translation\TranslationCoverageService;
use App\Tests\Support\TemporaryDirectories;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TranslationCoverageServiceTest extends TestCase
{
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeTemporaryDirectories();
    }

    public function testComputesCoveredMissingAndOrphanKeysPerLocale(): void
    {
        $projectDir = $this->makeProjectDir([
            'welcome' => 'Hello %name%',
            'goodbye' => 'Bye',
        ]);
        $pluginDir = $this->makePluginDir('de', [
            'welcome' => 'Hallo %name%',
            'extra' => 'Nur im Plugin',
        ]);

        $service = new TranslationCoverageService($this->makeRegistry(), $projectDir);
        $report = $service->coverageForPluginDirectory($pluginDir);

        $this->assertArrayHasKey('de', $report->coverage);
        $de = $report->coverage['de'];

        $this->assertTrue($de->isKnown);
        $this->assertSame(1, $de->covered);
        $this->assertSame(['goodbye'], $de->missing);
        $this->assertSame(['extra'], $de->orphans);
        $this->assertSame([], $de->placeholderMismatches);
    }

    public function testDetectsAPlaceholderMismatchOnASharedKey(): void
    {
        $projectDir = $this->makeProjectDir(['error' => 'Error: %detail%']);
        $pluginDir = $this->makePluginDir('de', ['error' => 'Fehler: %reason%']);

        $service = new TranslationCoverageService($this->makeRegistry(), $projectDir);
        $report = $service->coverageForPluginDirectory($pluginDir);

        $this->assertSame(
            ['missing' => ['detail'], 'extra' => ['reason']],
            $report->coverage['de']->placeholderMismatches['error'],
        );
    }

    public function testTreatsAnUnparseableLocaleCatalogAsUnknownRatherThanThrowing(): void
    {
        $projectDir = $this->makeProjectDir(['welcome' => 'Hello']);
        $pluginDir = $this->makePluginDir('de', ['welcome' => 'Hallo']);
        file_put_contents($pluginDir.'/translations/messages.fr.yaml', "key: [unterminated\n");

        $service = new TranslationCoverageService($this->makeRegistry(), $projectDir);
        $report = $service->coverageForPluginDirectory($pluginDir);

        $this->assertFalse($report->coverage['fr']->isKnown);
        $this->assertSame(0, $report->coverage['fr']->covered);
    }

    public function testCoverageForInstalledPluginReturnsNullForAnUnknownPluginId(): void
    {
        $service = new TranslationCoverageService($this->makeRegistry(), $this->makeProjectDir(['welcome' => 'Hello']));

        $this->assertNull($service->coverageForInstalledPlugin(new PluginId('not-installed')));
    }

    public function testCoverageForInstalledPluginResolvesTheInstalledPluginsDirectory(): void
    {
        $pluginsDir = $this->createTemporaryDirectory('anime-translation-coverage-installed-');
        $pluginDir = $pluginsDir.'/animedb-german';
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/messages.de.yaml', $this->toYaml(['welcome' => 'Hallo']));
        file_put_contents($pluginDir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-german',
            'name' => 'German',
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['de'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();

        $projectDir = $this->makeProjectDir(['welcome' => 'Hello']);
        $service = new TranslationCoverageService($registry, $projectDir);

        $report = $service->coverageForInstalledPlugin(new PluginId('animedb-german'));

        $this->assertNotNull($report);
        $this->assertSame(1, $report->coverage['de']->covered);
        $this->assertSame([], $report->coverage['de']->missing);
    }

    public function testCoverageForInstalledPluginReportsAManifestLocaleWithNoCatalogFileAsUnknown(): void
    {
        // The manifest declares 'fr' — App\Service\Plugin\AvailableLocalesProvider reads that same
        // field to offer 'fr' in the settings-page locale switcher — but the plugin only ships a
        // 'de' catalog file. Silently omitting 'fr' from the report would hide precisely the defect
        // this command exists to surface: a locale users can select with nothing translated behind it.
        $pluginsDir = $this->createTemporaryDirectory('anime-translation-coverage-partial-locales-');
        $pluginDir = $pluginsDir.'/animedb-partial';
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/messages.de.yaml', $this->toYaml(['welcome' => 'Hallo']));
        file_put_contents($pluginDir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-partial',
            'name' => 'Partial',
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['de', 'fr'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();

        $projectDir = $this->makeProjectDir(['welcome' => 'Hello']);
        $service = new TranslationCoverageService($registry, $projectDir);

        $report = $service->coverageForInstalledPlugin(new PluginId('animedb-partial'));

        $this->assertNotNull($report);
        $this->assertArrayHasKey('fr', $report->coverage);
        $this->assertFalse($report->coverage['fr']->isKnown);
        $this->assertSame(1, $report->coverage['de']->covered);
    }

    public function testCoverageForPluginDirectoryOfAnIntegrationTypeReturnsALocaleListWithNoCoverageFields(): void
    {
        $pluginDir = $this->createTemporaryDirectory('anime-translation-coverage-feature-');
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/animedb-widget.en.yaml', "welcome: Hello\n");
        file_put_contents($pluginDir.'/translations/animedb-widget.ru.yaml', "welcome: Привет\n");

        $service = new TranslationCoverageService($this->makeRegistry(), $this->makeProjectDir(['welcome' => 'Hello']));
        $report = $service->coverageForPluginDirectory($pluginDir, PluginType::Integration, 'animedb-widget');

        $this->assertSame(PluginType::Integration, $report->type);
        $this->assertSame(['en', 'ru'], $report->locales);
        $this->assertSame([], $report->coverage);
    }

    public function testCoverageForInstalledPluginOfAnIntegrationTypeHasNoUnknownEntriesForDeclaredLocales(): void
    {
        $pluginsDir = $this->createTemporaryDirectory('anime-translation-coverage-integration-installed-');
        $pluginDir = $pluginsDir.'/animedb-widget';
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/animedb-widget.ru.yaml', "welcome: Привет\n");
        file_put_contents($pluginDir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-widget',
            'name' => 'Widget',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            // Declares a locale ('fr') the plugin never shipped a catalog file for — unlike a
            // Translation plugin, this must not produce an 'unknown' entry: there is no reference
            // catalog for this domain to have found it missing against in the first place.
            'locales' => ['ru', 'fr'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();

        $service = new TranslationCoverageService($registry, $this->makeProjectDir(['welcome' => 'Hello']));
        $report = $service->coverageForInstalledPlugin(new PluginId('animedb-widget'));

        $this->assertNotNull($report);
        $this->assertSame([], $report->coverage);
        $this->assertSame(['ru'], $report->locales);
    }

    public function testIsMissingFallbackLocaleWhenNeitherTheCurrentLocaleNorItsFallbackChainIsShipped(): void
    {
        $service = new TranslationCoverageService($this->makeRegistry(), $this->makeProjectDir(['welcome' => 'Hello']));
        $report = PluginTranslationReport::featureLocales(PluginType::Integration, ['ru']);

        // Interface locale 'de' falls back to 'en' (issue #538) — neither is among ['ru'].
        $this->assertTrue($service->isMissingFallbackLocale($report, 'de'));
    }

    public function testIsMissingFallbackLocaleFalseWhenTheFallbackChainIsShipped(): void
    {
        $service = new TranslationCoverageService($this->makeRegistry(), $this->makeProjectDir(['welcome' => 'Hello']));
        $report = PluginTranslationReport::featureLocales(PluginType::Integration, ['ru']);

        // Interface locale 'kk' falls back to 'ru' (issue #538) — 'ru' is among ['ru'].
        $this->assertFalse($service->isMissingFallbackLocale($report, 'kk'));
    }

    public function testIsMissingFallbackLocaleAlwaysFalseForATranslationTypeReport(): void
    {
        $service = new TranslationCoverageService($this->makeRegistry(), $this->makeProjectDir(['welcome' => 'Hello']));
        $report = PluginTranslationReport::translation([]);

        $this->assertFalse($service->isMissingFallbackLocale($report, 'de'));
    }

    private function makeRegistry(): InstalledPluginsRegistry
    {
        $pluginsDir = sys_get_temp_dir().'/anime-translation-coverage-empty-registry-'.uniqid();

        return new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
    }

    /**
     * @param array<string, string> $messages
     */
    private function makeProjectDir(array $messages): string
    {
        $projectDir = $this->createTemporaryDirectory('anime-translation-coverage-app-');
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', $this->toYaml($messages));

        return $projectDir;
    }

    /**
     * @param array<string, string> $messages
     */
    private function makePluginDir(string $locale, array $messages): string
    {
        $pluginDir = $this->createTemporaryDirectory('anime-translation-coverage-plugin-');
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/messages.'.$locale.'.yaml', $this->toYaml($messages));

        return $pluginDir;
    }

    /**
     * @param array<string, string> $messages
     */
    private function toYaml(array $messages): string
    {
        $lines = [];
        foreach ($messages as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, json_encode($value));
        }

        return implode("\n", $lines)."\n";
    }
}
