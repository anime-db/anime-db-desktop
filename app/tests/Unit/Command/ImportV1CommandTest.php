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

namespace App\Tests\Unit\Command;

use App\Command\ImportV1Command;
use App\Repository\AnimeRepository;
use App\Repository\LabelRepository;
use App\Repository\StorageRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Import\V1\V1AnimeResolver;
use App\Service\Import\V1\V1CatalogReader;
use App\Service\Import\V1\V1ImportReportStore;
use App\Service\Import\V1\V1ImportService;
use App\Service\Media\AnimeCoverStorage;
use App\Service\Media\ImageNormalizer;
use App\Service\Sync\SyncReviewService;
use App\Service\WsPublisher;
use App\Tests\Support\CreatesInMemoryEntityManager;
use App\Tests\Support\TemporaryDirectories;
use App\Tests\Support\V1DatabaseBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class ImportV1CommandTest extends TestCase
{
    use CreatesInMemoryEntityManager;
    use TemporaryDirectories;

    private CommandTester $tester;

    private string $reportPath;

    /** @var list<array{0: string, 1: mixed}> */
    private array $published = [];

    private WsPublisher&\PHPUnit\Framework\MockObject\Stub $publisher;

    protected function setUp(): void
    {
        $this->published = [];
        $this->publisher = $this->createStub(WsPublisher::class);
        $this->publisher->method('publish')->willReturnCallback(function (string $event, mixed $data): void {
            $this->published[] = [$event, $data];
        });
        $entityManager = $this->createInMemoryEntityManager();
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3).'/translations/messages.en.yaml', 'en');

        $service = new V1ImportService(
            new V1CatalogReader(new NullLogger()),
            new V1AnimeResolver($entityManager, new LabelRepository($entityManager), new StudioRepository($entityManager), new StorageRepository($entityManager)),
            $entityManager,
            new AnimeRepository($entityManager),
            new SyncTombstoneRepository($entityManager),
            new SyncReviewItemRepository($entityManager),
            new SyncReviewService(new SyncReviewItemRepository($entityManager)),
            $this->publisher,
            $translator,
            new ImageNormalizer(),
            new AnimeCoverStorage(new ImageNormalizer(), $this->createTemporaryDirectory('media-')),
        );
        $this->reportPath = $this->createTemporaryDirectory('report-').'/import-v1-report.json';
        $this->tester = new CommandTester(new ImportV1Command($service, $translator, $this->publisher, new V1ImportReportStore($this->reportPath, new NullLogger())));
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testPrintsTheReportAfterAnImport(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 20);

        $this->tester->execute(['directory' => $builder->root]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Entries created: 20', $display);
        $this->assertStringContainsString('Genres with no counterpart', $display);
        $this->assertStringContainsString('Covers imported: 0', $display);
        $this->assertStringNotContainsString('import_v1.', $display, 'every message must be translated');
    }

    public function testPersistsTheReportAfterAnImport(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 20);

        $this->tester->execute(['directory' => $builder->root]);

        $this->assertFileExists($this->reportPath);
        $stored = json_decode((string) file_get_contents($this->reportPath), true);
        $this->assertSame(20, $stored['animeCreated']);
    }

    public function testWritesNoReportWhenTheImportFails(): void
    {
        $this->tester->execute(['directory' => $this->createTemporaryDirectory('v1-')]);

        $this->assertFileDoesNotExist($this->reportPath);
    }

    public function testNamesWhatItDidNotFindAndWhereItLooked(): void
    {
        $dir = $this->createTemporaryDirectory('v1-');

        $this->tester->execute(['directory' => $dir]);

        $this->assertSame(2, $this->tester->getStatusCode());
        $this->assertStringContainsString('not an AnimeDB v1 installation', $this->tester->getDisplay());
        $this->assertStringContainsString('anime.db', $this->tester->getDisplay());
    }

    public function testSecondImportIsRefusedAsTheCatalogIsNoLongerEmpty(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 3);
        $this->tester->execute(['directory' => $builder->root]);

        $this->tester->execute(['directory' => $builder->root]);

        $this->assertSame(3, $this->tester->getStatusCode());
        $this->assertStringContainsString('can only be imported into an empty catalog', (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()));
    }

    public function testNamesTheRecordThatBreaksAnInvariantAndImportsNothing(): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-'));
        $builder->item(['name' => 'Good', 'type' => 'feature']);
        $bad = $builder->item(['name' => ' ', 'type' => 'feature']);

        $this->tester->execute(['directory' => $builder->root]);

        $this->assertSame(4, $this->tester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        $this->assertStringContainsString(\sprintf('(v1 id %d) cannot be imported, so nothing was imported', $bad), $display);
        $this->assertStringNotContainsString('import_v1.', $display);
    }

    public function testPublishesTheWholeReportOnDone(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 20);

        $this->tester->execute(['directory' => $builder->root]);

        $done = array_values(array_filter($this->published, static fn (array $event): bool => $event[0] === 'import_v1.done'));
        $this->assertCount(1, $done);
        $this->assertSame(20, $done[0][1]['animeCreated']);
        foreach (array_keys(get_object_vars(new \App\Service\Import\V1\V1ImportResult())) as $field) {
            $this->assertArrayHasKey($field, $done[0][1]);
        }
        $this->assertSame([], array_filter($this->published, static fn (array $event): bool => $event[0] === 'import_v1.failed'));
    }

    public function testPublishesProgressForEveryPhase(): void
    {
        $builder = V1DatabaseBuilder::catalog($this->createTemporaryDirectory('v1-'), 20);

        $this->tester->execute(['directory' => $builder->root]);

        $phases = array_values(array_unique(array_map(static fn (array $event): string => $event[1]['phase'], array_filter($this->published, static fn (array $event): bool => $event[0] === 'import_v1.progress'))));
        $this->assertEqualsCanonicalizing(['read', 'records', 'covers'], $phases);
    }

    public function testPublishesTheRefusalWithThePathItLookedFor(): void
    {
        $this->tester->execute(['directory' => $this->createTemporaryDirectory('v1-')]);

        $failed = array_values(array_filter($this->published, static fn (array $event): bool => $event[0] === 'import_v1.failed'));
        $this->assertCount(1, $failed);
        $this->assertSame('not_v1_installation', $failed[0][1]['reason']);
        $this->assertStringContainsString('anime.db', $failed[0][1]['params']['path']);
        $this->assertSame([], array_filter($this->published, static fn (array $event): bool => $event[0] === 'import_v1.done'));
    }
}
