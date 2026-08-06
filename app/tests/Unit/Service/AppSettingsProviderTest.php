<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Enum\PaginationMode;
use App\Entity\ValueObject\PluginId;
use App\Service\AppSettingsProvider;
use PHPUnit\Framework\TestCase;

final class AppSettingsProviderTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testDefaultsToInfiniteScrollWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testDefaultsToInfiniteScrollWhenKeyIsMissing(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testDefaultsToInfiniteScrollWhenValueIsNotRecognized(): void
    {
        file_put_contents($this->configPath, json_encode(['paginationMode' => 'bogus']));

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testDefaultsToInfiniteScrollWhenFileIsNotValidJson(): void
    {
        file_put_contents($this->configPath, '{not json');

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testReadsClassicModeFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['paginationMode' => 'classic']));

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame(PaginationMode::Classic, $provider->getPaginationMode());
    }

    public function testGetLocaleReturnsNullWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider($this->configPath);

        $this->assertNull($provider->getLocale());
    }

    public function testGetLocaleReadsValueFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['locale' => 'ru']));

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame('ru', $provider->getLocale());
    }

    public function testSetLocaleCreatesConfigFileWhenMissing(): void
    {
        $provider = new AppSettingsProvider($this->configPath);

        $provider->setLocale('en');

        $this->assertSame('en', $provider->getLocale());
    }

    public function testSetLocaleOverwritesOnlyTheLocaleKey(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc', 'locale' => 'en']));

        $provider = new AppSettingsProvider($this->configPath);
        $provider->setLocale('ru');

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('ru', $data['locale']);
    }

    public function testSetLocaleCreatesMissingParentDirectory(): void
    {
        $configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'/nested/config.json';
        $provider = new AppSettingsProvider($configPath);

        $provider->setLocale('ru');

        $this->assertSame('ru', $provider->getLocale());

        unlink($configPath);
        rmdir(\dirname($configPath));
        rmdir(\dirname($configPath, 2));
    }

    public function testGetDefaultSearchPluginIdReturnsNullWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider($this->configPath);

        $this->assertNull($provider->getDefaultSearchPluginId());
    }

    public function testGetDefaultSearchPluginIdReturnsNullWhenValueIsMalformed(): void
    {
        file_put_contents($this->configPath, json_encode(['defaultSearchPluginId' => 'not_a_valid_id']));

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertNull($provider->getDefaultSearchPluginId());
    }

    public function testGetDefaultSearchPluginIdReadsValueFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));

        $provider = new AppSettingsProvider($this->configPath);

        $this->assertSame('animedb-shikimori', (string) $provider->getDefaultSearchPluginId());
    }

    public function testSetDefaultSearchPluginIdOverwritesOnlyThatKey(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider($this->configPath);
        $provider->setDefaultSearchPluginId(new PluginId('animedb-shikimori'));

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('animedb-shikimori', $data['defaultSearchPluginId']);
    }

    public function testSetDefaultSearchPluginIdWithNullClearsTheSetting(): void
    {
        file_put_contents($this->configPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));

        $provider = new AppSettingsProvider($this->configPath);
        $provider->setDefaultSearchPluginId(null);

        $this->assertNull($provider->getDefaultSearchPluginId());
    }

    public function testWriteConfigLeavesNoTemporaryFileBehind(): void
    {
        $provider = new AppSettingsProvider($this->configPath);
        $provider->setLocale('ru');

        $directory = \dirname($this->configPath);
        $leftovers = glob($directory.'/.config.json.*.tmp');

        $this->assertSame([], $leftovers);
    }

    public function testWriteConfigPreservesKeysWrittenByAnotherLayerConcurrently(): void
    {
        // Simulates native/config.js (another layer) having already written appSecret before
        // this process reads-modifies-writes locale, i.e. the read-modify-write cycle sees
        // the other layer's key rather than clobbering it.
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider($this->configPath);
        $provider->setLocale('ru');
        $provider->setDefaultSearchPluginId(new PluginId('animedb-shikimori'));

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('ru', $data['locale']);
        $this->assertSame('animedb-shikimori', $data['defaultSearchPluginId']);
    }

    public function testWriteConfigThrowsAndLeavesValidFileIntactWhenTemporaryWriteFails(): void
    {
        // A directory this process owns (unlike sys_get_temp_dir() itself, which is typically
        // root-owned with the sticky bit set, so chmod on it would silently no-op).
        $directory = sys_get_temp_dir().'/anime-config-test-dir-'.uniqid();
        mkdir($directory);
        $configPath = $directory.'/config.json';
        file_put_contents($configPath, json_encode(['appSecret' => 'abc']));

        // Strip write permission from the directory so the temporary file can never be created,
        // simulating a full disk / permission failure partway through the write.
        chmod($directory, 0500);

        $provider = new AppSettingsProvider($configPath);

        try {
            $provider->setLocale('ru');
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (\RuntimeException) {
            // expected
        } finally {
            chmod($directory, 0755);
        }

        $data = json_decode((string) file_get_contents($configPath), true);
        $this->assertSame('abc', $data['appSecret']);
        $this->assertArrayNotHasKey('locale', $data);

        unlink($configPath);
        rmdir($directory);
    }
}
