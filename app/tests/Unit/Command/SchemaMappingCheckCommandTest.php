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

use App\Command\SchemaMappingCheckCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Wiring only: that the command builds the database, introspects it and reports nothing on a clean
 * tree. Which differences count as divergences is pinned by MappingSchemaComparatorTest, where the
 * schemas can be shaped by hand.
 */
final class SchemaMappingCheckCommandTest extends KernelTestCase
{
    private const APP_DIR = __DIR__.'/../../..';

    public function testCleanTreeMatchesTheMappingAndLeavesNoTempDirectory(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $before = $this->tempDirs();

        $tester = new CommandTester(new SchemaMappingCheckCommand(self::APP_DIR, $entityManager));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($before, $this->tempDirs(), 'the throwaway directory must be removed');
    }

    /**
     * @return list<string>
     */
    private function tempDirs(): array
    {
        $found = glob(sys_get_temp_dir().'/animedb_mapping_check_*') ?: [];
        sort($found);

        return $found;
    }
}
