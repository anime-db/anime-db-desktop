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

namespace App\Tests\Functional\Import;

use App\Entity\Anime;
use App\Service\Import\V1\V1ImportService;
use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\TemporaryDirectories;
use App\Tests\Support\V1DatabaseBuilder;

/**
 * The importer through the real container: the autowired resolver interface, the entity
 * listeners that fire on persist/flush (search indexing, aggregate touching) and the real
 * transaction — none of which the in-memory unit tests have.
 */
final class V1ImportWiringTest extends FunctionalTestCase
{
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
        parent::tearDown();
    }

    public function testImportsThroughTheContainerAndKeepsDateAdd(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 30);
        $service = self::getContainer()->get(V1ImportService::class);
        $this->assertInstanceOf(V1ImportService::class, $service);

        $result = $service->import($builder->root);
        $this->entityManager()->clear();

        $this->assertSame(30, $result->animeCreated);
        $anime = $this->entityManager()->getRepository(Anime::class)->findOneBy(['title' => 'Title 1']);
        $this->assertInstanceOf(Anime::class, $anime);
        $this->assertSame('2015-02-02', $anime->getDateAdd()->format('Y-m-d'));
        $this->assertCount(4, $anime->getNames());
    }
}
