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

namespace App\Tests\Unit\DomainEvent\Catalog;

use App\Doctrine\DomainEventConnection;
use App\DomainEvent\Catalog\AnimeEventRecorder;
use App\DomainEvent\Catalog\AnimeSnapshotNormalizer;
use App\DomainEvent\DomainEventPublisher;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeSnapshot;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class AnimeEventRecorderTest extends TestCase
{
    private DomainEventConnection $connection;
    private AnimeEventRecorder $recorder;
    /** @var list<object> */
    private array $delivered = [];

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => DomainEventConnection::class], $config);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AnimeChanged::class, function (object $event): void {
            $this->delivered[] = $event;
        });
        $dispatcher->addListener(\App\Event\Catalog\AnimeRemoved::class, function (object $event): void {
            $this->delivered[] = $event;
        });
        $publisher = new DomainEventPublisher($dispatcher, new NullLogger());
        $this->connection->setDomainEventPublisher($publisher);

        $this->recorder = new AnimeEventRecorder($publisher, new AnimeSnapshotNormalizer(), new EntityManager($this->connection, $config));
    }

    public function testWithoutATransactionTheEventIsDeliveredAtOnce(): void
    {
        $this->recorder->recordChanged(7, ['title' => ['A', 'B']]);

        $this->assertCount(1, $this->delivered);
        $this->assertInstanceOf(AnimeChanged::class, $this->delivered[0]);
        $this->assertSame(7, $this->delivered[0]->animeId);
        $this->assertSame('A', $this->delivered[0]->changes['title']->before);
        $this->assertSame('B', $this->delivered[0]->changes['title']->after);
    }

    public function testInsideATransactionTheEventWaitsForTheOuterCommit(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->recorder->recordChanged(7, ['title' => ['A', 'B']]);
        $this->connection->commit();
        $this->assertSame([], $this->delivered);

        $this->connection->commit();
        $this->assertCount(1, $this->delivered);
    }

    public function testARollBackDropsTheEvent(): void
    {
        $this->connection->beginTransaction();
        $this->recorder->recordChanged(7, ['title' => ['A', 'B']]);
        $this->connection->rollBack();

        $this->assertCount(0, $this->delivered);

        // Nothing is left behind for the next delivery either.
        $this->recorder->recordChanged(8, ['title' => ['A', 'C']]);
        $this->assertCount(1, $this->delivered);
        $this->assertInstanceOf(AnimeChanged::class, $this->delivered[0]);
        $this->assertSame(8, $this->delivered[0]->animeId);
    }

    public function testAFieldThatEndedWhereItStartedProducesNoEvent(): void
    {
        $this->recorder->recordChanged(7, ['title' => ['A', 'A']]);

        $this->assertSame([], $this->delivered);
    }

    public function testRemovedCarriesTheSnapshot(): void
    {
        $snapshot = new AnimeSnapshot(['title' => 'A']);
        $this->recorder->recordRemoved(7, $snapshot);

        $this->assertInstanceOf(\App\Event\Catalog\AnimeRemoved::class, $this->delivered[0]);
        $this->assertSame($snapshot, $this->delivered[0]->snapshot);
    }
}
