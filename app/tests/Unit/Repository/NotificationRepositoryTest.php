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

namespace App\Tests\Unit\Repository;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Tests\Support\CreatesInMemoryEntityManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class NotificationRepositoryTest extends TestCase
{
    use CreatesInMemoryEntityManager;

    private EntityManager $entityManager;
    private NotificationRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
        $this->repository = new NotificationRepository($this->entityManager);
    }

    public function testCountsOnlyUnreadRecords(): void
    {
        $this->persist('a', 100);
        $read = $this->persist('b', 200);
        $read->markRead();
        $this->entityManager->flush();

        self::assertSame(1, $this->repository->countUnread());
    }

    public function testFindRecentReturnsTheNewestFirstLimited(): void
    {
        $this->persist('old', 100);
        $this->persist('new', 300);
        $this->persist('mid', 200);
        $this->entityManager->clear();

        $recent = $this->repository->findRecent(2);

        self::assertSame(['new', 'mid'], array_map(static fn (Notification $n) => $n->params['name'], $recent));
        self::assertCount(3, $this->repository->findAll());
    }

    public function testMarkReadTouchesOnlyTheGivenIds(): void
    {
        $first = $this->persist('a', 100);
        $second = $this->persist('b', 200);
        $late = $this->persist('c', 300);

        $changed = $this->repository->markRead([(int) $first->id, (int) $second->id]);
        $this->entityManager->clear();

        self::assertSame(2, $changed);
        self::assertSame(1, $this->repository->countUnread());
        $unread = $this->repository->findAll()[0];
        self::assertSame($late->id, $unread->id);
        self::assertFalse($unread->isRead());
    }

    public function testMarkReadKeepsTheTimeOfAnAlreadyReadRecordAndIgnoresNoIds(): void
    {
        $record = $this->persist('a', 100);
        self::assertSame(0, $this->repository->markRead([]));
        $this->repository->markRead([(int) $record->id]);
        $this->entityManager->clear();
        $firstReadAt = $this->repository->findAll()[0]->readAt;

        self::assertSame(0, $this->repository->markRead([(int) $record->id]));
        $this->entityManager->clear();

        self::assertEquals($firstReadAt, $this->repository->findAll()[0]->readAt);
    }

    public function testMarkAllReadMarksEveryUnreadRecordAndNeverDeletes(): void
    {
        $this->persist('a', 100);
        $this->persist('b', 200);

        self::assertSame(2, $this->repository->markAllRead());
        self::assertSame(0, $this->repository->countUnread());
        self::assertCount(2, $this->repository->findAll());
    }

    public function testAReadRecordNoLongerBlocksANewOneWithTheSameMergeKey(): void
    {
        $this->persist('a', 100, 'same-key');
        $this->repository->markAllRead();

        $this->persist('a', 200, 'same-key');

        self::assertSame(1, $this->repository->countUnread());
        self::assertCount(2, $this->repository->findAll());
    }

    private function persist(string $name, int $occurredAt, ?string $dedupKey = null): Notification
    {
        $record = new Notification('test_event', 'info', ['name' => $name], null, null, $dedupKey, (new \DateTimeImmutable())->setTimestamp($occurredAt));
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }
}
