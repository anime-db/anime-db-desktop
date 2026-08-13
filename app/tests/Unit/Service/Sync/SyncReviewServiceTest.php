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

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use App\Repository\SyncReviewItemRepository;
use App\Service\Sync\SyncReviewService;
use PHPUnit\Framework\TestCase;

final class SyncReviewServiceTest extends TestCase
{
    public function testCreatePersistsAndReturnsANewItem(): void
    {
        $repository = $this->createMock(SyncReviewItemRepository::class);
        $repository->expects($this->once())
            ->method('save')
            ->with($this->callback(
                fn (SyncReviewItem $item) => $item->payload === ['anime_ids' => [1, 2]] && !$item->isResolved(),
            ));

        $service = new SyncReviewService($repository);

        $item = $service->create(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);

        $this->assertSame(SyncReviewItemKind::PotentialDuplicate, $item->kind);
        $this->assertFalse($item->isResolved());
    }

    public function testFindUnresolvedDelegatesToRepository(): void
    {
        $expected = [new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]])];

        $repository = $this->createMock(SyncReviewItemRepository::class);
        $repository->expects($this->once())
            ->method('findAllUnresolvedOrderedByCreatedAt')
            ->willReturn($expected);

        $service = new SyncReviewService($repository);

        $this->assertSame($expected, $service->findUnresolved());
    }

    public function testResolveMarksItemResolvedAndSavesIt(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);

        $repository = $this->createMock(SyncReviewItemRepository::class);
        $repository->expects($this->once())
            ->method('save')
            ->with($item);

        $service = new SyncReviewService($repository);
        $service->resolve($item);

        $this->assertTrue($item->isResolved());
    }
}
