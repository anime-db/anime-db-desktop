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

use App\Command\StorageListCommand;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\StorageRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StorageListCommandTest extends TestCase
{
    public function testJsonOutputIsAnArrayOfIdPathAndType(): void
    {
        $first = new Storage('Main', 'D:\\Anime', StorageType::Folder);
        $second = new Storage('Usb', 'E:\\Видео', StorageType::External);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($first, 1);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($second, 2);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllScannable')->willReturn([$first, $second]);

        $tester = new CommandTester(new StorageListCommand($storages));
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            ['id' => 1, 'path' => 'D:\\Anime', 'type' => 'folder'],
            ['id' => 2, 'path' => 'E:\\Видео', 'type' => 'external'],
        ], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testJsonOutputIsAnEmptyArrayWithoutStorages(): void
    {
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllScannable')->willReturn([]);

        $tester = new CommandTester(new StorageListCommand($storages));
        $tester->execute(['--json' => true]);

        $this->assertSame('[]', trim($tester->getDisplay()));
    }
}
