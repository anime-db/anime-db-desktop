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

namespace App\Tests\Unit\Service;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeGenre;
use App\Entity\AnimeImage;
use App\Entity\AnimeName;
use App\Entity\AnimePluginData;
use App\Entity\AnimeSource;
use App\Entity\AnimeSyncState;
use App\Entity\AnimeTheme;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\AnimeTypeMigrator;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * AnimeTypeMigratorTest stubs/mocks the EntityManager, so it cannot observe what a
 * migration actually leaves in the database: whether the source row is truly gone
 * and whether every child row (genres/names/images/sources, studio/label join rows)
 * ended up attached to the new row rather than lost or left dangling on the old one.
 * This test round-trips both migration directions through a real EntityManager/SQLite
 * connection, following the same setup as AnimeDiscriminatorPersistenceTest.
 */
final class AnimeTypeMigratorPersistenceTest extends TestCase
{
    private EntityManager $entityManager;
    private string $mediaDir;

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

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        // Off by default on SQLite; needed so anime_plugin_data's ON DELETE CASCADE actually
        // fires below, the same way the real container-wired connection has it on via the
        // doctrine.middleware-tagged EnableForeignKeys (services.yaml) — this test builds its
        // own connection outside the container, so that middleware never runs here.
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->mediaDir = sys_get_temp_dir().'/anime-media-test-'.uniqid();
        mkdir($this->mediaDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->mediaDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testMigrateFromSeriesToMovieRemovesOldRowAndKeepsChildren(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')
            ->setDurationMinutes(24)
            ->setWatchStatus(WatchStatus::Plan);
        $source->setEpisodesCount(26);

        $sourceId = $this->persistSourceWithChildren($source);

        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);
        $target = $migrator->migrate($source, AnimeType::Movie);
        $targetId = $target->id;

        $this->assertOldRowGoneAndChildrenIntact($sourceId, $targetId, MovieAnime::class);
        $this->assertSame(24, $target->getDurationMinutes());
    }

    public function testMigrateFromMovieToExplicitSeriesClassRemovesOldRowAndKeepsChildren(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')
            ->setDurationMinutes(115)
            ->setWatchStatus(WatchStatus::Plan);

        $sourceId = $this->persistSourceWithChildren($source);

        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);
        $target = $migrator->migrate($source, AnimeType::Tv);
        $targetId = $target->id;

        $this->assertOldRowGoneAndChildrenIntact($sourceId, $targetId, TvAnime::class);
        $this->assertSame(115, $target->getDurationMinutes());
    }

    public function testMigrateRepointsPluginDataToNewIdInsteadOfLosingItToCascade(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $sourceId = $source->id;
        $this->assertNotNull($sourceId);

        $pluginData = new AnimePluginData($source, new PluginId('animedb-shikimori'), ['mal_id' => 1]);
        $this->entityManager->persist($pluginData);
        $this->entityManager->flush();

        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);
        $target = $migrator->migrate($source, AnimeType::Tv);
        $targetId = $target->id;
        $this->assertNotNull($targetId);
        $this->assertNotSame($sourceId, $targetId);

        $this->entityManager->clear();

        $rows = $this->entityManager->getRepository(AnimePluginData::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame($targetId, $rows[0]->anime->id);
        $this->assertSame(['mal_id' => 1], $rows[0]->getPayload());
    }

    /**
     * Regression coverage for issue #365's "camp #13": anime_sync_state isn't part of the Anime
     * entity (like anime_plugin_data), so without repointing, a type migration would silently
     * lose the reconciliation snapshot to the ON DELETE CASCADE on the removed source row —
     * making every participant look "changed" on the very next sync run.
     */
    public function testMigrateRepointsSyncStateToNewIdInsteadOfLosingItToCascade(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $sourceId = $source->id;
        $this->assertNotNull($sourceId);

        $syncState = new AnimeSyncState($source, 'local', WatchStatus::Watching, null, new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($syncState);
        $this->entityManager->flush();

        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);
        $target = $migrator->migrate($source, AnimeType::Tv);
        $targetId = $target->id;
        $this->assertNotNull($targetId);
        $this->assertNotSame($sourceId, $targetId);

        $this->entityManager->clear();

        $rows = $this->entityManager->getRepository(AnimeSyncState::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame($targetId, $rows[0]->anime->id);
        $this->assertSame('local', $rows[0]->participantId);
        $this->assertSame(WatchStatus::Watching, $rows[0]->lastStatus);
    }

    private function persistSourceWithChildren(Anime $source): int
    {
        $studio = new Studio();
        $studio->rename('Sunrise');
        $label = new Label();
        $label->rename('favorite');

        $source->addGenre(GenreCode::Action)
            ->addTheme(ThemeCode::Isekai)
            ->setDemographic(Demographic::Shounen)
            ->addStudio($studio)
            ->addLabel($label)
            ->addName('Trigun', AnimeNameType::English)
            ->addImage('images/frame1.jpg')
            ->addSource('https://shikimori.one/animes/1');

        $this->entityManager->persist($studio);
        $this->entityManager->persist($label);
        $this->entityManager->persist($source);
        $this->entityManager->flush();

        $id = $source->id;
        $this->assertNotNull($id);

        return $id;
    }

    /** @param class-string<Anime> $expectedTargetClass */
    private function assertOldRowGoneAndChildrenIntact(int $sourceId, ?int $targetId, string $expectedTargetClass): void
    {
        $this->assertNotNull($targetId);
        $this->entityManager->clear();

        $this->assertNull($this->entityManager->find(Anime::class, $sourceId));

        $target = $this->entityManager->find(Anime::class, $targetId);
        $this->assertInstanceOf($expectedTargetClass, $target);

        $this->assertSame([GenreCode::Action], $target->getGenreCodes());
        $this->assertCount(1, $this->entityManager->getRepository(AnimeGenre::class)->findAll());

        $this->assertSame([ThemeCode::Isekai], $target->getThemeCodes());
        $this->assertCount(1, $this->entityManager->getRepository(AnimeTheme::class)->findAll());

        $this->assertSame(Demographic::Shounen, $target->getDemographic());

        $names = $target->getNames();
        $this->assertCount(1, $names);
        $name = $names->first();
        $this->assertNotFalse($name);
        $this->assertSame('Trigun', $name->name);
        $this->assertCount(1, $this->entityManager->getRepository(AnimeName::class)->findAll());

        $images = $target->getImages();
        $this->assertCount(1, $images);
        $image = $images->first();
        $this->assertNotFalse($image);
        $this->assertSame('images/frame1.jpg', $image->source);
        $this->assertCount(1, $this->entityManager->getRepository(AnimeImage::class)->findAll());

        $sources = $target->getSources();
        $this->assertCount(1, $sources);
        $link = $sources->first();
        $this->assertNotFalse($link);
        $this->assertSame('https://shikimori.one/animes/1', $link->url);
        $this->assertCount(1, $this->entityManager->getRepository(AnimeSource::class)->findAll());

        $studios = $this->entityManager->getRepository(Studio::class)->findAll();
        $this->assertCount(1, $studios);
        $this->assertTrue($target->getStudios()->contains($studios[0]));
        $studioAnimes = $studios[0]->getAnimes();
        $this->assertCount(1, $studioAnimes);
        $studioAnime = $studioAnimes->first();
        $this->assertNotFalse($studioAnime);
        $this->assertSame($targetId, $studioAnime->id);

        $labels = $this->entityManager->getRepository(Label::class)->findAll();
        $this->assertCount(1, $labels);
        $this->assertTrue($target->getLabels()->contains($labels[0]));
        $labelAnimes = $labels[0]->getAnimes();
        $this->assertCount(1, $labelAnimes);
        $labelAnime = $labelAnimes->first();
        $this->assertNotFalse($labelAnime);
        $this->assertSame($targetId, $labelAnime->id);
    }

    public function testMigrateRenamesMediaDirectoryToNewId(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setWatchStatus(WatchStatus::Plan)->setCover('cover.jpg');

        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $sourceId = $source->id;
        $this->assertNotNull($sourceId);

        $sourceDir = $this->mediaDir.'/'.$sourceId;
        mkdir($sourceDir, 0o777, true);
        file_put_contents($sourceDir.'/cover.jpg', 'fake-cover-bytes');

        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);
        $target = $migrator->migrate($source, AnimeType::Tv);
        $targetId = $target->id;
        $this->assertNotNull($targetId);
        $this->assertNotSame($sourceId, $targetId);

        $this->assertDirectoryDoesNotExist($sourceDir);
        $targetDir = $this->mediaDir.'/'.$targetId;
        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir.'/cover.jpg');
        $this->assertSame('fake-cover-bytes', file_get_contents($targetDir.'/cover.jpg'));
        $this->assertSame('cover.jpg', $target->getCover());
    }

    public function testMigrateSucceedsWhenAnimeHasNoMediaDirectory(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($source);
        $this->entityManager->flush();

        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);
        $target = $migrator->migrate($source, AnimeType::Tv);

        $this->assertInstanceOf(TvAnime::class, $target);
        $this->assertDirectoryDoesNotExist($this->mediaDir.'/'.$target->id);
    }

    public function testMigrateRollsBackWhenMediaDirectoryCannotBeMoved(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setWatchStatus(WatchStatus::Plan)->setCover('cover.jpg');

        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $sourceId = $source->id;
        $this->assertNotNull($sourceId);

        $sourceDir = $this->mediaDir.'/'.$sourceId;
        mkdir($sourceDir, 0o777, true);
        file_put_contents($sourceDir.'/cover.jpg', 'fake-cover-bytes');

        // Occupy every id the new row could plausibly get with a plain file, so
        // rename(directory, existing-non-directory) fails on every retry.
        for ($id = $sourceId + 1; $id <= $sourceId + 5; ++$id) {
            file_put_contents($this->mediaDir.'/'.$id, 'occupied');
        }

        $connection = $this->entityManager->getConnection();
        $migrator = new AnimeTypeMigrator($this->entityManager, $this->mediaDir);

        try {
            $migrator->migrate($source, AnimeType::Tv);
            $this->fail('Expected migration to throw when the media directory move fails.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM anime'));
        $this->assertSame($sourceId, (int) $connection->fetchOne('SELECT id FROM anime'));
        $this->assertDirectoryExists($sourceDir);
        $this->assertFileExists($sourceDir.'/cover.jpg');
    }
}
