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

namespace App\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;

/**
 * The entity layer must not know the service layer: contracts an entity needs (such as the v1
 * import DTO behind Anime::fromV1()) live in App\Entity, their implementations in App\Service.
 */
final class EntityLayerDependencyTest extends TestCase
{
    public function testTheEntityLayerDoesNotDependOnTheServiceLayer(): void
    {
        $dir = \dirname(__DIR__, 3).'/src/Entity';
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php') {
                $this->assertStringNotContainsString('use App\\Service\\', (string) file_get_contents($file->getPathname()), $file->getPathname());
                ++$checked;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }
}
