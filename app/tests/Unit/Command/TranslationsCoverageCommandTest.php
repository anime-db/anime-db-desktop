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

namespace App\Tests\Unit\Command;

use App\Command\TranslationsCoverageCommand;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\TranslationCoverageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TranslationsCoverageCommandTest extends TestCase
{
    public function testReportsCoverageForAPluginDirectoryNotAmongTheInstalledPlugins(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\ngoodbye: Bye\n");

        // A plugin checkout on disk, never registered with InstalledPluginsRegistry.
        $pluginDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-plugin-'.uniqid();
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/messages.de.yaml', "welcome: Hallo\nextra: Nur hier\n");
        $this->writeManifest($pluginDir, 'animedb-checkout', 'translation', ['de']);

        $registry = $this->makeEmptyRegistry();
        $command = $this->makeCommand(new TranslationCoverageService($registry, $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--path' => $pluginDir]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Locale: de', $display);
        $this->assertStringContainsString('Covered: 1', $display);
        $this->assertStringContainsString('goodbye', $display);
        $this->assertStringContainsString('extra', $display);
    }

    public function testFailsWhenNeitherPluginNorPathIsGiven(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $command = $this->makeCommand(new TranslationCoverageService($this->makeEmptyRegistry(), $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailsWhenTheGivenPluginIsNotInstalled(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $command = $this->makeCommand(new TranslationCoverageService($this->makeEmptyRegistry(), $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--plugin' => 'not-installed']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testPathFailsWithAClearErrorWhenTheDirectoryHasNoManifest(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        // A directory with a translations/ folder but no manifest.json at all.
        $pluginDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-no-manifest-'.uniqid();
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/messages.de.yaml', "welcome: Hallo\n");

        $command = $this->makeCommand(new TranslationCoverageService($this->makeEmptyRegistry(), $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--path' => $pluginDir]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('manifest.json', $tester->getDisplay());
    }

    public function testPathOnAnIntegrationPluginListsItsLocalesWithoutCoverageFigures(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $pluginDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-integration-'.uniqid();
        mkdir($pluginDir.'/translations', recursive: true);
        file_put_contents($pluginDir.'/translations/animedb-widget.en.yaml', "welcome: Hello\n");
        file_put_contents($pluginDir.'/translations/animedb-widget.ru.yaml', "welcome: Привет\n");
        $this->writeManifest($pluginDir, 'animedb-widget', 'integration', null);

        $command = $this->makeCommand(new TranslationCoverageService($this->makeEmptyRegistry(), $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--path' => $pluginDir]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('en', $display);
        $this->assertStringContainsString('ru', $display);
        $this->assertStringNotContainsString('Covered:', $display);
    }

    /**
     * Issue #540: the current interface locale ('de') falls back to 'en' (issue #538), and the
     * plugin ships neither — the command must warn that this plugin's UI will show raw keys.
     */
    public function testPluginCommandWarnsWhenTheInterfaceLocaleHasNoMatchingFallback(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $registry = $this->makeInstalledIntegrationPlugin(['ru']);
        $command = $this->makeCommand(new TranslationCoverageService($registry, $projectDir), currentLocale: 'de');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--plugin' => 'animedb-widget']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('raw translation keys', $tester->getDisplay());
    }

    /**
     * Same plugin locales as above, but the interface locale ('kk') falls back to 'ru' (issue
     * #538), which the plugin does ship — no warning.
     */
    public function testPluginCommandDoesNotWarnWhenTheFallbackChainIsShipped(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $registry = $this->makeInstalledIntegrationPlugin(['ru']);
        $command = $this->makeCommand(new TranslationCoverageService($registry, $projectDir), currentLocale: 'kk');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--plugin' => 'animedb-widget']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('raw translation keys', $tester->getDisplay());
    }

    /**
     * @param list<string> $locales
     */
    private function makeInstalledIntegrationPlugin(array $locales): InstalledPluginsRegistry
    {
        $pluginsDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-installed-'.uniqid();
        $pluginDir = $pluginsDir.'/animedb-widget';
        mkdir($pluginDir.'/translations', recursive: true);
        foreach ($locales as $locale) {
            file_put_contents($pluginDir.'/translations/animedb-widget.'.$locale.'.yaml', "welcome: Hi\n");
        }
        $this->writeManifest($pluginDir, 'animedb-widget', 'integration', $locales);

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();

        return $registry;
    }

    /**
     * @param list<string>|null $locales
     */
    private function writeManifest(string $pluginDir, string $id, string $type, ?array $locales): void
    {
        file_put_contents($pluginDir.'/manifest.json', (string) json_encode(array_filter([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'type' => $type,
            'features' => $type === 'integration' ? ['filler' => true] : null,
            'locales' => $locales,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ], static fn (mixed $value): bool => $value !== null)));
    }

    private function makeCommand(TranslationCoverageService $coverageService, ?string $currentLocale = null): TranslationsCoverageCommand
    {
        $configPath = sys_get_temp_dir().'/anime-translations-coverage-cmd-settings-'.uniqid().'.json';
        $settings = new AppSettingsProvider(new AppConfigStore($configPath));
        if ($currentLocale !== null) {
            $settings->setLocale($currentLocale);
        }

        return new TranslationsCoverageCommand($coverageService, $settings);
    }

    private function makeEmptyRegistry(): InstalledPluginsRegistry
    {
        $pluginsDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-registry-'.uniqid();

        return new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
    }
}
