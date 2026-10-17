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

use App\Command\StorageConnectedCommand;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Event\StorageConnected;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class StorageConnectedCommandTest extends TestCase
{
    public function testDispatchesStorageConnectedForExistingStorage(): void
    {
        $storage = new Storage('Main', 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 7);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($storage);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(StorageConnected::class, static function (StorageConnected $event) use (&$received): void {
            $received[] = $event->storageId;
        });

        $tester = new CommandTester(new StorageConnectedCommand($entityManager, $dispatcher));
        $exitCode = $tester->execute(['id' => '7']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([7], $received);
    }

    public function testFailsWithoutDispatchingForUnknownStorage(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(StorageConnected::class, static function (StorageConnected $event) use (&$received): void {
            $received[] = $event->storageId;
        });

        $tester = new CommandTester(new StorageConnectedCommand($entityManager, $dispatcher));
        $exitCode = $tester->execute(['id' => '999']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame([], $received);
    }

    public function testFailsForNonNumericId(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');

        $tester = new CommandTester(new StorageConnectedCommand($entityManager, new EventDispatcher()));

        $this->assertSame(Command::FAILURE, $tester->execute(['id' => 'abc']));
    }
}
