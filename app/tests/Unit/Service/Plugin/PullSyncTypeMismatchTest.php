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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Model\AnimeType as ContractAnimeType;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\SyncReviewItem;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Tests\Support\BuildsPullSyncService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/** A pull compares the source's type with the record's and flags a difference; it never changes a type (issue #1002). */
final class PullSyncTypeMismatchTest extends TestCase
{
    use BuildsPullSyncService;

    private const ID = 'animedb-shikimori';
    private const OTHER_ID = 'animedb-myanimelist';

    private EntityManager $entityManager;
    private Anime $anime;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->anime = new MovieAnime();
        $this->anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->anime->rememberExternalId(new PluginId(self::ID), '1');
        $this->entityManager->persist($this->anime);
        $this->entityManager->flush();
    }

    private function pull(?ContractAnimeType $sourceType, string $pluginId = self::ID): void
    {
        $sync = $this->createStub(SyncInterface::class);
        $sync->method('pull')->willReturn([new SyncItem('1', SyncStatus::Plan, 'Cowboy Bebop', type: $sourceType)]);

        $registry = new SyncRegistry([$pluginId => $sync], new PluginsConfigStore(sys_get_temp_dir().'/anime-type-mismatch-'.uniqid().'.json'));
        $this->assertTrue($this->newPullSyncService($registry)->pull(new PluginId($pluginId), $sync));
    }

    /** @return list<SyncReviewItem> */
    private function items(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(SyncReviewItem::class)->findBy(['kind' => SyncReviewItemKind::TypeMismatch], ['id' => 'ASC']);
    }

    private function resolveAll(): void
    {
        foreach ($this->items() as $item) {
            $item->resolve();
        }
        $this->entityManager->flush();
    }

    private function recordType(): AnimeType
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Anime::class, $this->anime->id)?->getType() ?? throw new \LogicException('The record is gone.');
    }

    public function testADifferentSourceTypeRaisesOneItemAndKeepsTheRecordsType(): void
    {
        $this->pull(ContractAnimeType::Tv);

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame(['anime_id' => $this->anime->id, 'plugin_id' => self::ID, 'source_type' => 'tv'], $items[0]->payload);
        $this->assertSame(AnimeType::Movie, $this->recordType());
    }

    public function testRepeatedPullWithTheSameSourceTypeRaisesNoNewItem(): void
    {
        $this->pull(ContractAnimeType::Tv);
        $this->pull(ContractAnimeType::Tv);

        $this->assertCount(1, $this->items());
    }

    public function testRepeatedPullAfterTheItemWasKeptRaisesNoNewItem(): void
    {
        $this->pull(ContractAnimeType::Tv);
        $this->resolveAll();
        $this->pull(ContractAnimeType::Tv);

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->isResolved());
    }

    public function testANewSourceTypeRaisesANewItem(): void
    {
        $this->pull(ContractAnimeType::Tv);
        $this->resolveAll();
        $this->pull(ContractAnimeType::Ova);

        $items = $this->items();
        $this->assertCount(2, $items);
        $this->assertSame('ova', $items[1]->payload['source_type']);
        $this->assertFalse($items[1]->isResolved());
    }

    public function testMatchingTypesCloseTheOpenItem(): void
    {
        $this->pull(ContractAnimeType::Tv);
        $this->pull(ContractAnimeType::Movie);

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->isResolved());
    }

    public function testASourceWithoutATypeRaisesNothingAndClosesNothing(): void
    {
        $this->pull(null);
        $this->assertSame([], $this->items());

        $this->pull(ContractAnimeType::Tv);
        $this->pull(null);

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]->isResolved());
    }

    public function testDeletingTheRecordClosesTheItem(): void
    {
        $this->pull(ContractAnimeType::Tv);

        $item = $this->items()[0];
        $this->assertTrue($item->forgetAnime($this->anime->id ?? 0));
        $this->assertTrue($item->isResolved());
    }

    private function rememberSecondPlugin(): void
    {
        $this->anime->rememberExternalId(new PluginId(self::OTHER_ID), '1');
        $this->entityManager->flush();
    }

    public function testAnotherPluginReportingTheSameTypeRaisesItsOwnItem(): void
    {
        $this->rememberSecondPlugin();
        $this->pull(ContractAnimeType::Tv);
        $this->resolveAll();
        $this->pull(ContractAnimeType::Tv, self::OTHER_ID);

        $items = $this->items();
        $this->assertCount(2, $items);
        $this->assertSame(self::OTHER_ID, $items[1]->payload['plugin_id']);
        $this->assertFalse($items[1]->isResolved());
    }

    public function testMatchingTypeFromOnePluginKeepsTheOpenItemOfAnother(): void
    {
        $this->rememberSecondPlugin();
        $this->pull(ContractAnimeType::Tv, self::OTHER_ID);
        $this->pull(ContractAnimeType::Movie);

        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame(self::OTHER_ID, $items[0]->payload['plugin_id']);
        $this->assertFalse($items[0]->isResolved());
    }
}
