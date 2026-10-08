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

namespace App\Tests\Unit\Service\Import\V1;

use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\SeriesAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\LabelRepository;
use App\Repository\StorageRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Import\Exception\InvalidV1InstallationException;
use App\Service\Import\V1\V1AnimeResolver;
use App\Service\Import\V1\V1CatalogReader;
use App\Service\Import\V1\V1ImportService;
use App\Service\Media\AnimeCoverStorage;
use App\Service\Media\CoverUploadException;
use App\Service\Media\ImageNormalizer;
use App\Service\Sync\SyncReviewService;
use App\Service\WsPublisher;
use App\Tests\Support\CreatesInMemoryEntityManager;
use App\Tests\Support\TemporaryDirectories;
use App\Tests\Support\V1DatabaseBuilder;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\Translator;

final class V1ImportServiceTest extends TestCase
{
    use CreatesInMemoryEntityManager;
    use TemporaryDirectories;

    private EntityManager $entityManager;
    private V1ImportService $service;
    private string $mediaDir;
    private SyncTombstoneRepository $tombstones;

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
        $this->tombstones = new SyncTombstoneRepository($this->entityManager);
        $this->mediaDir = $this->createTemporaryDirectory('media-');
        $this->service = $this->createService(new ImageNormalizer(), new AnimeCoverStorage(new ImageNormalizer(), $this->mediaDir));
    }

    private function createService(ImageNormalizer $normalizer, AnimeCoverStorage $storage): V1ImportService
    {
        return new V1ImportService(
            new V1CatalogReader(new NullLogger()),
            new V1AnimeResolver(
                $this->entityManager,
                new LabelRepository($this->entityManager),
                new StudioRepository($this->entityManager),
                new StorageRepository($this->entityManager),
            ),
            $this->entityManager,
            new AnimeRepository($this->entityManager),
            $this->tombstones,
            new SyncReviewItemRepository($this->entityManager),
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            $this->createStub(WsPublisher::class),
            new Translator('en'),
            $normalizer,
            $storage,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testImportsASyntheticCatalogInOneGo(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'));

        $result = $this->service->import($builder->root);
        $this->entityManager->clear();

        $this->assertSame(194, $result->animeCreated);
        $this->assertSame(194, (new AnimeRepository($this->entityManager))->countAll());
        $this->assertSame(194, $result->withStatusFromLabel + $result->withDefaultStatus);
        $this->assertGreaterThan(0, $result->withStatusFromLabel);
        $this->assertSame(194 * 2, $result->sources);
        // The duplicate "Alt N" is dropped: four names per record remain.
        $this->assertSame(194 * 4, $result->namesTotal());
        $this->assertSame(194 * 2, $result->namesJapanese);
        $this->assertSame(194, $result->namesRussian);
        $this->assertSame(194, $result->namesUnknownLocale);
        $this->assertGreaterThan(0, $result->genresDroppedByDesign);
        $this->assertContains('Fable', $result->unmappedGenreNames);
        $this->assertGreaterThan(0, $result->durationsCleared);
        $this->assertGreaterThan(0, $result->endDatesSynthesized);
        $this->assertSame(1, $result->storagesCreated);
        $this->assertSame(1, $result->storagesUnavailable);

        // The status labels never became catalog labels.
        $labelNames = array_map(static fn (Label $label): string => $label->name, $this->entityManager->getRepository(Label::class)->findAll());
        $this->assertSame(['Online'], $labelNames);
        $this->assertCount(9, $this->entityManager->getRepository(Studio::class)->findAll());
        $this->assertCount(1, $this->entityManager->getRepository(Storage::class)->findAll());
    }

    public function testResolvesTheStudioThroughTheV1Dictionary(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $studio = $builder->studio('Madhouse');
        $builder->item(['name' => 'Dictionary', 'type' => 'feature', 'studio' => $studio]);

        $this->service->import($builder->root);
        $this->entityManager->clear();

        $anime = $this->entityManager->getRepository(Anime::class)->findOneBy(['title' => 'Dictionary']);
        $this->assertInstanceOf(Anime::class, $anime);
        $this->assertSame(['Madhouse'], array_map(static fn (Studio $s): string => $s->name, $anime->getStudios()->toArray()));
        $this->assertCount(1, $this->entityManager->getRepository(Studio::class)->findAll());
    }

    public function testKeepsAPlainTextStudioWhenTheDictionaryHasNoSuchRow(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->studio('Madhouse');
        $builder->item(['name' => 'Plain', 'type' => 'feature', 'studio' => 'Bones']);

        $this->service->import($builder->root);
        $this->entityManager->clear();

        $anime = $this->entityManager->getRepository(Anime::class)->findOneBy(['title' => 'Plain']);
        $this->assertInstanceOf(Anime::class, $anime);
        $this->assertSame(['Bones'], array_map(static fn (Studio $s): string => $s->name, $anime->getStudios()->toArray()));
    }

    public function testKeepsDateAddAndDateUpdateFromV1(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->item(['name' => 'Old', 'type' => 'feature', 'date_add' => '2014-02-08 14:59:28', 'date_update' => '2015-03-04 10:11:12']);

        $this->service->import($builder->root);
        $this->entityManager->clear();

        $anime = $this->entityManager->getRepository(Anime::class)->findOneBy(['title' => 'Old']);
        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('2014-02-08 14:59:28', $anime->getDateAdd()->format('Y-m-d H:i:s'));
        $this->assertSame('2015-03-04 10:11:12', $anime->getDateUpdate()->format('Y-m-d H:i:s'));
    }

    public function testFilmWithoutEndDateIsCompletedAndTvIsWatchingAndFlagged(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->item(['name' => 'Film', 'type' => 'feature', 'date_premiere' => '2010-04-01']);
        $builder->item(['name' => 'Running', 'type' => 'tv', 'date_premiere' => '2010-04-01', 'episodes_number' => 20]);
        $builder->item(['name' => 'Finished', 'type' => 'tv', 'date_premiere' => '2010-04-01', 'date_end' => '2010-09-01']);

        $result = $this->service->import($builder->root);
        $this->entityManager->clear();

        $repository = $this->entityManager->getRepository(Anime::class);
        $film = $repository->findOneBy(['title' => 'Film']);
        $running = $repository->findOneBy(['title' => 'Running']);
        $finished = $repository->findOneBy(['title' => 'Finished']);
        $this->assertInstanceOf(Anime::class, $film);
        $this->assertInstanceOf(TvAnime::class, $running);
        $this->assertInstanceOf(Anime::class, $finished);

        $this->assertSame(WatchStatus::Completed, $film->getWatchStatus());
        $this->assertSame('2010-04-01', $film->getDateEnd()?->format('Y-m-d'));
        $this->assertSame(WatchStatus::Watching, $running->getWatchStatus());
        $this->assertNull($running->getDateEnd());
        $this->assertSame(WatchStatus::Completed, $finished->getWatchStatus());

        $this->assertSame(1, $result->needsAttention);
        $this->assertSame(1, $result->endDatesSynthesized);
        $items = $this->entityManager->getRepository(SyncReviewItem::class)->findAll();
        $this->assertCount(1, $items);
        $this->assertSame(SyncReviewItemKind::NeedsCorrection, $items[0]->kind);
        $this->assertSame([$running->id], $items[0]->payload['anime_ids']);
    }

    public function testCompletedWithoutDatesIsLoweredAndReportedWhateverTheType(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->label('Просмотрено');
        $film = $builder->item(['name' => 'Undated film', 'type' => 'feature', 'date_premiere' => null]);
        $builder->itemLabel($film, 'Просмотрено');
        $builder->item(['name' => 'Undated tv', 'type' => 'tv', 'date_premiere' => null]);

        $result = $this->service->import($builder->root);
        $this->entityManager->clear();

        $repository = $this->entityManager->getRepository(Anime::class);
        $filmEntity = $repository->findOneBy(['title' => 'Undated film']);
        $this->assertInstanceOf(Anime::class, $filmEntity);
        $this->assertSame(WatchStatus::Plan, $filmEntity->getWatchStatus());
        $this->assertSame(2, $result->statusesDowngraded);
        $this->assertSame(2, $result->needsAttention);
        $messages = array_map(static fn (SyncReviewItem $item): string => (string) $item->payload['message'], $this->entityManager->getRepository(SyncReviewItem::class)->findAll());
        // The translator of this test has no catalogue: the key stands for the text.
        $this->assertSame(['import_v1.review_status_downgraded', 'import_v1.review_status_downgraded'], $messages);
    }

    public function testNormalisesValuesTheV2SchemaRefuses(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->item(['name' => 'Odd', 'type' => 'feature', 'duration' => 0, 'episodes_number' => 5, 'country' => null, 'rating' => 3]);

        $result = $this->service->import($builder->root);
        $this->entityManager->clear();

        $anime = $this->entityManager->getRepository(Anime::class)->findOneBy(['title' => 'Odd']);
        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertNull($anime->getDurationMinutes());
        $this->assertNull($anime->getCountries());
        $this->assertSame(3, $anime->getUserRating()?->value);
        $this->assertSame(1, $result->durationsCleared);
        $this->assertSame(['Odd'], $result->episodesDroppedTitles);
    }

    public function testSeriesKeepsItsEpisodeCountAndCompletedStatusCopiesIt(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->label('Просмотрено');
        $id = $builder->item(['name' => 'Series', 'type' => 'ova', 'episodes_number' => 4, 'date_end' => '2011-01-01']);
        $builder->itemLabel($id, 'Просмотрено');

        $this->service->import($builder->root);
        $this->entityManager->clear();

        $anime = $this->entityManager->getRepository(Anime::class)->findOneBy(['title' => 'Series']);
        $this->assertInstanceOf(SeriesAnime::class, $anime);
        $this->assertSame(4, $anime->getEpisodesCount());
        $this->assertSame(4, $anime->getWatchedEpisodes());
    }

    public function testRefusesANonEmptyCatalogBeforeWritingAnything(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 5);
        $anime = new TvAnime();
        $anime->setTitle('Mine')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->tombstones->record('plugin', 'ext-1', new \DateTimeImmutable());

        try {
            $this->service->import($builder->root);
            $this->fail('Expected a refusal');
        } catch (InvalidV1InstallationException $exception) {
            $this->assertSame(InvalidV1InstallationException::REASON_CATALOG_NOT_EMPTY, $exception->reasonKey);
        }

        $this->assertSame(1, (new AnimeRepository($this->entityManager))->countAll());
        $this->assertTrue($this->tombstones->exists('plugin', 'ext-1'), 'the guard must fire before the cleanup');
        $this->assertCount(0, $this->entityManager->getRepository(Studio::class)->findAll());
    }

    public function testClearsLeftoverTombstonesAndReviewItemsUnconditionally(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->item(['name' => 'One', 'type' => 'feature']);
        $this->tombstones->record('plugin-a', 'ext-1', new \DateTimeImmutable());
        $this->tombstones->record('plugin-b', 'ext-2', new \DateTimeImmutable(), true);
        (new SyncReviewService(new SyncReviewItemRepository($this->entityManager)))->create(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [7, 8]]);

        $this->service->import($builder->root);

        $this->assertFalse($this->tombstones->exists('plugin-a', 'ext-1'));
        $this->assertFalse($this->tombstones->exists('plugin-b', 'ext-2'));
        $this->assertCount(0, $this->entityManager->getRepository(SyncReviewItem::class)->findAll());
    }

    public function testAFailureRollsEverythingBack(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->label('Online');
        $good = $builder->item(['name' => 'Good', 'type' => 'feature', 'studio' => 'Madhouse']);
        $builder->itemLabel($good, 'Online');
        $builder->item(['name' => ' ', 'type' => 'feature']);
        $this->tombstones->record('plugin', 'ext-1', new \DateTimeImmutable());

        try {
            $this->service->import($builder->root);
            $this->fail('Expected the empty title to abort the import');
        } catch (\Throwable) {
        }

        $this->entityManager->clear();
        $this->assertSame(0, (new AnimeRepository($this->entityManager))->countAll());
        $this->assertTrue($this->tombstones->exists('plugin', 'ext-1'), 'the cleanup belongs to the same transaction');
        $this->assertCount(0, $this->entityManager->getRepository(Label::class)->findAll());
    }

    public function testRefusesADirectoryThatIsNotAV1Installation(): void
    {
        $dir = $this->createTemporaryDirectory('v1-');

        try {
            $this->service->import($dir);
            $this->fail('Expected a refusal');
        } catch (InvalidV1InstallationException $exception) {
            $this->assertSame(InvalidV1InstallationException::REASON_NOT_V1_INSTALLATION, $exception->reasonKey);
            $this->assertStringContainsString($dir.'/app/Resources/anime.db', (string) $exception->params['%path%']);
        }
    }

    public function testRefusesADatabaseWithoutTheV1Tables(): void
    {
        $dir = $this->createTemporaryDirectory('v1-');
        mkdir($dir.'/app/Resources', 0o777, true);
        (new \PDO('sqlite:'.$dir.'/app/Resources/anime.db'))->exec('CREATE TABLE item (id INTEGER PRIMARY KEY)');

        $this->expectException(InvalidV1InstallationException::class);

        $this->service->import($dir);
    }

    public function testNeverWritesToTheV1Installation(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 12)->withMedia();
        $database = $builder->root.'/app/Resources/anime.db';
        $before = [hash_file('sha256', $database), $this->listing($builder->root)];

        $this->service->import($builder->root);

        $this->assertSame($before, [hash_file('sha256', $database), $this->listing($builder->root)]);
    }

    public function testMovesACoverIntoTheMediaDirAsWebp(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'))->withMedia();
        $builder->item(['name' => 'With cover', 'type' => 'feature', 'cover' => '2014/02/08/145928/1.jpg']);
        mkdir($builder->root.'/web/media/2014/02/08/145928', 0o777, true);
        file_put_contents($builder->root.'/web/media/2014/02/08/145928/1.jpg', $this->jpeg());

        $result = $this->service->import($builder->root);
        $this->entityManager->clear();

        $this->assertSame(1, $result->coversImported);
        $this->assertSame(0, $result->coversMissing);
        $anime = $this->entityManager->getRepository(Anime::class)->findOneBy(['title' => 'With cover']);
        $this->assertInstanceOf(Anime::class, $anime);
        $cover = $anime->getCover();
        $this->assertNotNull($cover);
        $this->assertStringEndsWith('.webp', $cover);
        $path = $this->mediaDir.'/'.$anime->id.'/'.$cover;
        $this->assertFileExists($path);
        $this->assertSame('WEBP', substr((string) file_get_contents($path), 8, 4));
        $this->assertSame([$cover], array_map('basename', glob($this->mediaDir.'/'.$anime->id.'/*') ?: []));
    }

    public function testMissingAndBrokenCoversAreCountedNotFatal(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'))->withMedia();
        $builder->item(['name' => 'No file', 'type' => 'feature', 'cover' => 'gone.jpg']);
        $builder->item(['name' => 'Not a picture', 'type' => 'feature', 'cover' => 'text.jpg']);
        $builder->item(['name' => 'Empty', 'type' => 'feature', 'cover' => 'empty.jpg']);
        $builder->item(['name' => 'No cover', 'type' => 'feature']);
        $builder->item(['name' => 'Escapes', 'type' => 'feature', 'cover' => '../../app/Resources/anime.db']);
        file_put_contents($builder->root.'/web/media/text.jpg', 'not an image');
        file_put_contents($builder->root.'/web/media/empty.jpg', '');

        $result = $this->service->import($builder->root);
        $this->entityManager->clear();

        $this->assertSame(0, $result->coversImported);
        $this->assertSame(5, $result->coversMissing);
        $this->assertSame(5, (new AnimeRepository($this->entityManager))->countAll());
        $this->assertSame([], glob($this->mediaDir.'/*') ?: []);
    }

    public function testInstallationWithoutMediaDirImportsEveryCoverAsMissing(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 12);
        $this->assertDirectoryDoesNotExist($builder->root.'/web/media');

        $result = $this->service->import($builder->root);

        $this->assertSame(12, $result->animeCreated);
        $this->assertSame(0, $result->coversImported);
        $this->assertSame(12, $result->coversMissing);
    }

    public function testFailureAfterTheCoversLeavesNoOrphanDirectories(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'))->withMedia();
        $builder->item(['name' => 'First', 'type' => 'feature', 'cover' => 'a.jpg']);
        $builder->item(['name' => 'Second', 'type' => 'feature', 'cover' => 'a.jpg']);
        file_put_contents($builder->root.'/web/media/a.jpg', $this->jpeg());
        // A plain file where the second entry's directory belongs makes its store() throw, after the first
        // entry has already written its own directory.
        file_put_contents($this->mediaDir.'/2', 'in the way');

        $thrown = null;
        try {
            $this->service->import($builder->root);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(CoverUploadException::class, $thrown);

        $this->assertSame([$this->mediaDir.'/2'], glob($this->mediaDir.'/*') ?: []);
        $this->assertSame(0, (new AnimeRepository($this->entityManager))->countAll());
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function listing(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = (string) $file;
        }
        sort($files);

        return $files;
    }
}
