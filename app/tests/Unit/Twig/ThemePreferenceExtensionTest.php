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

namespace App\Tests\Unit\Twig;

use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Twig\ThemePreferenceExtension;
use PHPUnit\Framework\TestCase;

final class ThemePreferenceExtensionTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-theme-preference-extension-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createExtension(): ThemePreferenceExtension
    {
        return new ThemePreferenceExtension(new AppSettingsProvider(new AppConfigStore($this->configPath)));
    }

    public function testExposesTheSavedThemeAsAThemePreferenceFunction(): void
    {
        file_put_contents($this->configPath, json_encode(['themePreference' => 'dark']));

        $functions = $this->createExtension()->getFunctions();
        $this->assertCount(1, $functions);
        $this->assertSame('theme_preference', $functions[0]->getName());

        $callable = $functions[0]->getCallable();
        $this->assertIsCallable($callable);
        $this->assertSame('dark', $callable());
    }

    public function testDefaultsToSystemWhenNothingIsSaved(): void
    {
        $callable = $this->createExtension()->getFunctions()[0]->getCallable();
        $this->assertIsCallable($callable);

        $this->assertSame('system', $callable());
    }
}
