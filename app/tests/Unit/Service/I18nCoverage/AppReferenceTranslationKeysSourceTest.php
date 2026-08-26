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

namespace App\Tests\Unit\Service\I18nCoverage;

use App\Service\I18nCoverage\AppReferenceTranslationKeysSource;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\TranslationCoverageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves issue #515's requirement that the app's reference key *count* is never counted a second
 * way: this asserts equality against the real {@see TranslationCoverageService::referenceKeyCount()}
 * (not a fake standing in for it) reading the exact same project directory.
 */
final class AppReferenceTranslationKeysSourceTest extends TestCase
{
    public function testKeyCountMatchesTranslationCoverageServiceReferenceKeyCount(): void
    {
        $projectDir = $this->makeProjectDir(['welcome' => 'Hello', 'goodbye' => 'Bye', 'nested' => ['deep' => 'Value']]);

        $source = new AppReferenceTranslationKeysSource($projectDir);
        $coverageService = new TranslationCoverageService($this->makeRegistry(), $projectDir);

        self::assertCount($coverageService->referenceKeyCount(), $source->keys());
    }

    public function testKeysAreTheFlattenedDotNotationKeysOfTheReferenceCatalog(): void
    {
        $projectDir = $this->makeProjectDir(['welcome' => 'Hello', 'nested' => ['deep' => 'Value']]);

        $source = new AppReferenceTranslationKeysSource($projectDir);

        self::assertSame(['welcome', 'nested.deep'], $source->keys());
    }

    private function makeRegistry(): InstalledPluginsRegistry
    {
        $pluginsDir = sys_get_temp_dir().'/anime-i18n-coverage-empty-registry-'.uniqid();

        return new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
    }

    /**
     * @param array<string, mixed> $messages
     */
    private function makeProjectDir(array $messages): string
    {
        $projectDir = sys_get_temp_dir().'/anime-i18n-coverage-app-'.uniqid();
        mkdir($projectDir.'/translations', recursive: true);
        file_put_contents($projectDir.'/translations/messages.en.yaml', Yaml::dump($messages));

        return $projectDir;
    }
}
