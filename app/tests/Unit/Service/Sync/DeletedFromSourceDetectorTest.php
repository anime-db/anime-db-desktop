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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeSyncState;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeSyncStateRepository;
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
 * review, never deleted; storage-backed records are protected; a record that also carries another
 * active sync plugin's AnimeSyncState snapshot row is a conflict rather than a plain removal — a
 * cached external_id for that other plugin alone is not enough (issue #863).
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

    /**
     * Scenario 4 (issue #863): a cached external_id for another *active* plugin, without an
     * AnimeSyncState snapshot row for it, is not enough to make this a conflict — the plugin
     * never actually synced this record as a list item, so its absence from the record's current
     * state tells us nothing about whether the title is still present there.
     */
    public function testAnotherActivePluginWithOnlyACachedExternalIdAndNoSnapshotIsNotAConflict(): void
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
        $this->assertSame(SyncReviewItemKind::DeletedFromSource, $items[0]->kind);
        $this->assertSame(['anime_id' => $anime->id, 'deleted_from' => 'animedb-shikimori'], $items[0]->payload);
    }

    /**
     * Scenario 5 (issue #863): another *active* plugin that also carries an AnimeSyncState
     * snapshot row for this record genuinely still has it as a list item — a real conflict, not a
     * plain removal.
     */
    public function testRemovedRecordStillLinkedToAnotherActivePluginIsAConflict(): void
    {
        $anime = $this->persistAnime(['animedb-shikimori' => '10', 'animedb-mal' => '99']);
        $this->seedSyncState($anime, 'animedb-mal');
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

    /**
     * Scenario 6 (issue #863): a plugin that carries an AnimeSyncState snapshot row but is not
     * *active* is still not a conflict — "still in the list on an abandoned, now-inactive
     * tracker" is not a reason to withhold the plain removal flag (behaviour unchanged by #863).
     */
    public function testLinkToAnInactivePluginIsNotAConflict(): void
    {
        $anime = $this->persistAnime(['animedb-shikimori' => '10', 'animedb-mal' => '99']);
        $this->seedSyncState($anime, 'animedb-mal');
        $this->entityManager->flush();

        // 'animedb-mal' has a snapshot row but is not active for sync → SyncRegistry does not
        // list it, so this is a plain removal, not a conflict.
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
            new AnimeSyncStateRepository($this->entityManager),
        );
    }

    /**
     * Seeds an AnimeSyncState snapshot row as if a prior pull/push reconciliation had already
     * confirmed $anime as a list item for $participantId — the only thing that distinguishes a
     * genuine cross-source link from a merely cached external_id (issue #863).
     */
    private function seedSyncState(Anime $anime, string $participantId): void
    {
        $this->entityManager->persist(new AnimeSyncState($anime, $participantId, WatchStatus::Plan, null, new \DateTimeImmutable()));
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
