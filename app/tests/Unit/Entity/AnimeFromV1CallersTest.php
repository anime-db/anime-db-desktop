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
 * Anime::fromV1() is the one authorised breach of the dateAdd invariant, and its argument type
 * cannot lock it: any code can build a V1AnimeRecord. So the call sites are pinned instead — only
 * the v1 importer may call it.
 */
final class AnimeFromV1CallersTest extends TestCase
{
    private const array ALLOWED = ['Service/Import/V1/V1ImportService.php'];

    public function testOnlyTheImporterCallsFromV1(): void
    {
        $srcDir = \dirname(__DIR__, 3).'/src';
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/^(?!\s*\*).*::fromV1\(/m', (string) file_get_contents($file->getPathname())) === 1) {
                $callers[] = substr($file->getPathname(), \strlen($srcDir) + 1);
            }
        }
        sort($callers);

        $this->assertSame(self::ALLOWED, $callers);
    }

    public function testFactoryAndRecordAreMarkedInternal(): void
    {
        $factory = new \ReflectionMethod(\App\Entity\Anime::class, 'fromV1');
        $record = new \ReflectionClass(\App\Service\Import\V1\V1AnimeRecord::class);

        $this->assertStringContainsString('@internal', (string) $factory->getDocComment());
        $this->assertStringContainsString('@internal', (string) $record->getDocComment());
    }
}
