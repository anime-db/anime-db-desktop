<?php

declare(strict_types=1);

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

namespace App\Tests\Unit\MessageHandler;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\ExternalIdConflictMessage;
use App\MessageHandler\ExternalIdConflictMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class ExternalIdConflictMessageHandlerTest extends TestCase
{
    private EntityManager $entityManager;
    private SyncReviewService $reviews;
    private ExternalIdConflictMessageHandler $handler;
    private int $ownerId;
    private int $otherId;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->reviews = new SyncReviewService(new SyncReviewItemRepository($this->entityManager));
        $this->handler = new ExternalIdConflictMessageHandler(new AnimeRepository($this->entityManager), $this->reviews);

        $owner = $this->anime('Owner');
        $owner->rememberExternalId(new PluginId('aa-one'), 'X');
        $owner->rememberExternalId(new PluginId('aa-two'), 'Y');
        $other = $this->anime('Other');
        $this->entityManager->flush();
        $this->ownerId = (int) $owner->id;
        $this->otherId = (int) $other->id;
    }

    private function anime(string $title): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function message(string $plugin = 'aa-one', string $id = 'X'): ExternalIdConflictMessage
    {
        return new ExternalIdConflictMessage($plugin, $id, $this->ownerId, $this->otherId);
    }

    /** @return array<\App\Entity\SyncReviewItem> */
    private function items(): array
    {
        return $this->reviews->findAllByKind(SyncReviewItemKind::PotentialDuplicate);
    }

    public function testCreatesItemWithSortedPairAndConflict(): void
    {
        // Owner has the higher id here: swap roles so the pair must be sorted.
        $high = $this->anime('High');
        $high->rememberExternalId(new PluginId('aa-three'), 'Z');
        $this->entityManager->flush();
        ($this->handler)(new ExternalIdConflictMessage('aa-three', 'Z', (int) $high->id, $this->ownerId));

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame([$this->ownerId, (int) $high->id], $items[0]->payload['anime_ids']);
        $this->assertSame([['plugin_id' => 'aa-three', 'external_id' => 'Z', 'owner_id' => (int) $high->id, 'anime_id' => $this->ownerId]], $items[0]->payload['conflicts']);
    }

    public function testRepeatedMessageIsIdempotentAndOtherPluginIsAppended(): void
    {
        ($this->handler)($this->message());
        ($this->handler)($this->message());
        ($this->handler)($this->message('aa-two', 'Y'));

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertCount(2, $items[0]->payload['conflicts']);
    }

    public function testExtendsOpenItemRaisedByTitlesEvenForALargerCluster(): void
    {
        $third = $this->anime('Third');
        $thirdId = (int) $third->id;
        $this->reviews->create(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [$this->ownerId, $this->otherId, $thirdId]]);

        // The conflicting record is the third one, not the first non-owner.
        ($this->handler)(new ExternalIdConflictMessage('aa-one', 'X', $this->ownerId, $thirdId));

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame(
            [['plugin_id' => 'aa-one', 'external_id' => 'X', 'owner_id' => $this->ownerId, 'anime_id' => $thirdId]],
            $items[0]->payload['conflicts'],
        );
        $this->assertSame([$this->ownerId, $this->otherId, $thirdId], $items[0]->payload['anime_ids']);
    }

    public function testClosedItemWithConflictsSuppressesThePairForAllPlugins(): void
    {
        ($this->handler)($this->message());
        $this->reviews->resolve($this->items()[0]);

        ($this->handler)($this->message());
        ($this->handler)($this->message('aa-two', 'Y'));

        $this->assertCount(1, $this->items());
    }

    public function testClosedItemByTitleDoesNotSuppressAConflict(): void
    {
        $item = $this->reviews->create(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [$this->ownerId, $this->otherId]]);
        $this->reviews->resolve($item);

        ($this->handler)($this->message());

        $items = $this->items();
        $this->assertCount(2, $items);
        $this->assertFalse($items[1]->isResolved());
        $this->assertTrue($items[1]->hasConflicts());
    }

    public function testNothingWhenAnimeIsGoneOrOwnerNoLongerHoldsTheId(): void
    {
        ($this->handler)(new ExternalIdConflictMessage('aa-one', 'X', $this->ownerId, 9999));
        ($this->handler)(new ExternalIdConflictMessage('aa-one', 'NOPE', $this->ownerId, $this->otherId));
        ($this->handler)(new ExternalIdConflictMessage('aa-one', 'X', $this->otherId, $this->ownerId));

        $this->assertSame([], $this->items());
    }
}
