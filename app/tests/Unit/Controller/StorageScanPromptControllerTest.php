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

use App\Controller\StorageScanPromptController;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class StorageScanPromptControllerTest extends TestCase
{
    public function testPromptRendersFormWithStorage(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 7);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_prompt.html.twig', ['storage' => $storage])
            ->willReturn('<html></html>');

        $controller = new StorageScanPromptController($twig);
        $response = $controller->prompt($storage);

        $this->assertSame(200, $response->getStatusCode());
    }
}
