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

namespace App\Tests\Unit\Entity\ValueObject;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use PHPUnit\Framework\TestCase;

final class PluginIdTest extends TestCase
{
    public function testAcceptsOfficialPluginFormat(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $this->assertSame('animedb-shikimori', $pluginId->value);
        $this->assertSame('animedb-shikimori', (string) $pluginId);
    }

    public function testAcceptsCommunityVendorFormat(): void
    {
        $pluginId = new PluginId('acme-my-plugin');

        $this->assertSame('acme-my-plugin', $pluginId->value);
    }

    public function testRejectsValueWithoutVendorSeparator(): void
    {
        $this->expectException(InvalidPluginIdException::class);
        new PluginId('shikimori');
    }

    public function testRejectsUppercaseValue(): void
    {
        $this->expectException(InvalidPluginIdException::class);
        new PluginId('AnimeDB-Shikimori');
    }

    public function testRejectsEmptyValue(): void
    {
        $this->expectException(InvalidPluginIdException::class);
        new PluginId('');
    }

    public function testRejectsTrailingHyphen(): void
    {
        $this->expectException(InvalidPluginIdException::class);
        new PluginId('animedb-');
    }
}
