<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use AnimeDb\PluginContracts\SyncInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\SyncReviewItemRepository;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Source-side removal detection (issue #217): a record gone from a plugin's list is flagged for
 * review, never deleted; storage-backed records are protected; a record still linked to another
 * active sync plugin is a conflict rather than a plain removal.
 */
final class DeletedFromSourceDetectorTest extends TestCase
{
    private EntityManager $entityManager;
    private PluginId $pluginId;

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

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->pluginId = new PluginId('animedb-shikimori');
    }

    public function testStorageBackedRecordIsNeverFlagged(): void
    {
        $storage = new Storage('Main folder', sys_get_temp_dir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        $anime = $this->persistAnime(['animedb-shikimori' => '10']);
        $anime->setStorage($storage);
        $this->entityManager->flush();

        $this->detector(new SyncRegistry([], $this->store([])))->detect($this->pluginId, ['10' => $anime]);

        $this->assertSame([], $this->reviewItems());
    }

    public function testRemovedRecordWithoutOtherSourceIsFlaggedAsDeletedFromSource(): void
    {
        $anime = $this->persistAnime(['animedb-shikimori' => '10']);
        $this->entityManager->flush();

        $this->detector(new SyncRegistry([], $this->store([])))->detect($this->pluginId, ['10' => $anime]);

        $items = $this->reviewItems();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
        $this->assertSame(['anime_id' => $anime->id, 'deleted_from' => 'animedb-shikimori'], $items[0]->payload);
    }

    public function testRemovedRecordStillLinkedToAnotherActivePluginIsAConflict(): void
    {
        $anime = $this->persistAnime(['animedb-shikimori' => '10', 'animedb-mal' => '99']);
        $this->entityManager->flush();

        $registry = new SyncRegistry(
            ['animedb-mal' => $this->createStub(SyncInterface::class)],
            $this->store(['animedb-mal']),
        );

        $this->detector($registry)->detect($this->pluginId, ['10' => $anime]);

        $items = $this->reviewItems();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletionConflict, $items[0]->kind);
        $this->assertSame([
            'anime_id' => $anime->id,
            'deleted_from' => 'animedb-shikimori',
            'still_present_on' => ['animedb-mal'],
        ], $items[0]->payload);
    }

    public function testLinkToAnInactivePluginIsNotAConflict(): void
    {
        $anime = $this->persistAnime(['animedb-shikimori' => '10', 'animedb-mal' => '99']);
        $this->entityManager->flush();

        // 'animedb-mal' is linked but not active for sync → SyncRegistry does not list it, so this
        // is a plain removal, not a conflict.
        $this->detector(new SyncRegistry([], $this->store([])))->detect($this->pluginId, ['10' => $anime]);

        $items = $this->reviewItems();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
    }

    public function testAlreadyFlaggedRecordIsNotFlaggedAgainOnARepeatedRun(): void
    {
        $anime = $this->persistAnime(['animedb-shikimori' => '10']);
        $this->entityManager->flush();

        $detector = $this->detector(new SyncRegistry([], $this->store([])));
        // Two consecutive pulls where the title stays gone from the source — flagged once only.
        $detector->detect($this->pluginId, ['10' => $anime]);
        $detector->detect($this->pluginId, ['10' => $anime]);

        $this->assertCount(1, $this->reviewItems());
    }

    private function detector(SyncRegistry $registry): DeletedFromSourceDetector
    {
        return new DeletedFromSourceDetector(
            $registry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
        );
    }

    /**
     * @param array<string, string> $externalIds pluginId => externalId to remember on the record
     */
    private function persistAnime(array $externalIds): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        foreach ($externalIds as $pluginId => $externalId) {
            $anime->rememberExternalId(new PluginId($pluginId), $externalId);
        }
        $this->entityManager->persist($anime);

        return $anime;
    }

    /**
     * @param list<string> $activePluginIds plugins whose features.sync should be on
     */
    private function store(array $activePluginIds): PluginsConfigStore
    {
        $settings = [];
        foreach ($activePluginIds as $id) {
            $settings[$id] = ['features' => ['sync' => true]];
        }

        $path = sys_get_temp_dir().'/anime-deleted-detector-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($settings));

        return new PluginsConfigStore($path);
    }

    /**
     * @return SyncReviewItem[]
     */
    private function reviewItems(): array
    {
        return $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
    }
}
