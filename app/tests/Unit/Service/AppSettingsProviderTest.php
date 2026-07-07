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
}
