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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testSetAndGetName(): void
    {
        $storage = new Storage();
        $storage->setName('Main folder');

        $this->assertSame('Main folder', $storage->getName());
    }

    public function testSetAndGetType(): void
    {
        $storage = new Storage();
        $storage->setType(StorageType::Folder);

        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    public function testSetAndGetPath(): void
    {
        $storage = new Storage();
        $storage->setPath('D:\\Anime');

        $this->assertSame('D:\\Anime', $storage->getPath());
    }

    public function testDateUpdateDefaultsToNull(): void
    {
        $storage = new Storage();

        $this->assertNull($storage->getDateUpdate());
    }

    public function testFileModifiedDefaultsToNull(): void
    {
        $storage = new Storage();

        $this->assertNull($storage->getFileModified());
    }

    public function testMarkScannedSetsFileModified(): void
    {
        $storage = new Storage();
        $fileModified = new \DateTimeImmutable('2026-07-01 12:00:00');
        $storage->markScanned($fileModified);

        $this->assertSame($fileModified, $storage->getFileModified());
    }

    public function testMarkScannedSetsDateUpdateToNow(): void
    {
        $storage = new Storage();
        $before = new \DateTimeImmutable();
        $storage->markScanned(new \DateTimeImmutable('2026-07-01 12:00:00'));

        $this->assertGreaterThanOrEqual($before, $storage->getDateUpdate());
    }
}
