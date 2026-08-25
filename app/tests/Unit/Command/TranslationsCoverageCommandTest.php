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

        $registry = $this->makeEmptyRegistry();
        $command = new TranslationsCoverageCommand(new TranslationCoverageService($registry, $projectDir));
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

        $command = new TranslationsCoverageCommand(new TranslationCoverageService($this->makeEmptyRegistry(), $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailsWhenTheGivenPluginIsNotInstalled(): void
    {
        $projectDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', "welcome: Hello\n");

        $command = new TranslationsCoverageCommand(new TranslationCoverageService($this->makeEmptyRegistry(), $projectDir));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--plugin' => 'not-installed']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    private function makeEmptyRegistry(): InstalledPluginsRegistry
    {
        $pluginsDir = sys_get_temp_dir().'/anime-translations-coverage-cmd-registry-'.uniqid();

        return new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
    }
}
