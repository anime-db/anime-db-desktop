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

namespace App\Tests\Unit\Entity;

use App\Entity\Anime;
use PHPUnit\Framework\TestCase;

final class AnimeTest extends TestCase
{
    public function testMetadataDefaultsToNull(): void
    {
        $anime = new Anime();

        $this->assertNull($anime->getMetadata());
    }

    public function testSetAndGetTitle(): void
    {
        $anime = new Anime();
        $anime->setTitle('Cowboy Bebop');

        $this->assertSame('Cowboy Bebop', $anime->getTitle());
    }

    public function testSetTitleReturnsSelf(): void
    {
        $anime = new Anime();

        $this->assertSame($anime, $anime->setTitle('Trigun'));
    }

    public function testSetAndGetMetadata(): void
    {
        $metadata = [
            'mal_id' => 1,
            'studios' => ['Sunrise'],
            'external_ids' => ['shikimori' => 1],
        ];

        $anime = new Anime();
        $anime->setMetadata($metadata);

        $this->assertSame($metadata, $anime->getMetadata());
    }

    public function testSetMetadataToNull(): void
    {
        $anime = new Anime();
        $anime->setMetadata(['mal_id' => 1]);
        $anime->setMetadata(null);

        $this->assertNull($anime->getMetadata());
    }

    public function testSetMetadataReturnsSelf(): void
    {
        $anime = new Anime();

        $this->assertSame($anime, $anime->setMetadata(null));
    }
}
