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
 * Anime::restoreTimestamps() rewrites the history the constructor stamps, so only the v1 importer
 * may call it; and the entity layer must not know the import layer.
 */
final class AnimeRestoreTimestampsCallersTest extends TestCase
{
    private const array ALLOWED = ['Service/Import/V1/V1AnimeFactory.php'];

    public function testOnlyTheV1FactoryCallsRestoreTimestamps(): void
    {
        $callers = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            if (preg_match('/^(?!\s*\*).*->restoreTimestamps\(/m', $code) === 1) {
                $callers[] = $relative;
            }
        }
        sort($callers);

        $this->assertSame(self::ALLOWED, $callers);
    }

    public function testTheEntityLayerDoesNotDependOnTheServiceLayer(): void
    {
        foreach ($this->sourceFiles() as $relative => $code) {
            if (str_starts_with($relative, 'Entity/')) {
                $this->assertStringNotContainsString('use App\\Service\\', $code, $relative);
            }
        }
    }

    public function testRestoreTimestampsRefusesAnUpdateBeforeTheAdd(): void
    {
        $anime = new \App\Entity\MovieAnime();
        $added = new \DateTimeImmutable('2014-02-08');

        $anime->restoreTimestamps($added);
        $this->assertSame($added, $anime->getDateAdd());
        $this->assertSame($added, $anime->getDateUpdate());

        $this->expectException(\InvalidArgumentException::class);
        $anime->restoreTimestamps($added, new \DateTimeImmutable('2014-02-07'));
    }

    /** @return \Generator<string, string> */
    private function sourceFiles(): \Generator
    {
        $srcDir = \dirname(__DIR__, 3).'/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php') {
                yield substr($file->getPathname(), \strlen($srcDir) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
