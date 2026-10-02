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

namespace App\Tests\Unit\Controller;

use App\Controller\NavController;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\StorageRepository;
use App\Service\Storage\StorageAvailabilityService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class NavControllerTest extends TestCase
{
    private function setStorageId(Storage $storage, int $id): void
    {
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);
    }

    private function controller(StorageRepository $storages, Environment $twig): NavController
    {
        return new NavController($storages, new StorageAvailabilityService(), $twig);
    }

    public function testListsOnlyConnectedWritableStoragesAndCountsTheRest(): void
    {
        $connected = new Storage('Main folder', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($connected, 1);

        $missingPath = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'storage-missing-'.uniqid();
        $disconnected = new Storage('Backup folder', $missingPath, StorageType::External);
        $this->setStorageId($disconnected, 2);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$connected, $disconnected]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('nav/_add_menu_scan_section.html.twig', $this->callback(
                static fn (array $params): bool => $params['connectedStorages'] === [$connected]
                    && $params['disconnectedCount'] === 1
                    && $params['hasScannableStorages'] === true,
            ))
            ->willReturn('<li></li>');

        $this->controller($storages, $twig)->addMenuScanSection();
    }

    public function testExcludesNonWritableStorageTypesFromTheScanSectionEntirely(): void
    {
        $readOnly = new Storage('Optical disc', sys_get_temp_dir(), StorageType::ExternalR);
        $this->setStorageId($readOnly, 1);

        $video = new Storage('DVD player', sys_get_temp_dir(), StorageType::Video);
        $this->setStorageId($video, 2);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$readOnly, $video]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('nav/_add_menu_scan_section.html.twig', $this->callback(
                static fn (array $params): bool => $params['connectedStorages'] === []
                    && $params['disconnectedCount'] === 0
                    && $params['hasScannableStorages'] === false,
            ))
            ->willReturn('<li></li>');

        $this->controller($storages, $twig)->addMenuScanSection();
    }

    public function testNoScannableStorageAtAllIsFlagged(): void
    {
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('nav/_add_menu_scan_section.html.twig', $this->callback(
                static fn (array $params): bool => $params['hasScannableStorages'] === false
                    && $params['disconnectedCount'] === 0,
            ))
            ->willReturn('<li></li>');

        $this->controller($storages, $twig)->addMenuScanSection();
    }
}
