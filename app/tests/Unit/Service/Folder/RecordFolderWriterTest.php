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

namespace App\Tests\Unit\Service\Folder;

use AnimeDb\PluginContracts\Folder\ForeignFileException;
use AnimeDb\PluginContracts\Folder\RecordFolderFileState;
use AnimeDb\PluginContracts\Folder\RecordHasNoFolderException;
use AnimeDb\PluginContracts\Folder\StorageNotWritableException;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\RecordFolderFile;
use App\Entity\Storage;
use App\Entity\ValueObject\PluginId;
use App\Service\Folder\RecordFolderWriter;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real EntityManager/SQLite and a real temporary storage tree: the checks are about files on disk. */
final class RecordFolderWriterTest extends TestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private EntityManager $entityManager;
    private StorageMarkerService $markers;
    private string $root;
    private string $mediaDir;

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
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $this->markers = new StorageMarkerService($this->entityManager);

        $base = sys_get_temp_dir().'/anime-record-folder-writer-test-'.uniqid();
        $this->root = $base.'/storage';
        $this->mediaDir = $base.'/media';
        mkdir($this->root.'/Record', recursive: true);
        mkdir($this->mediaDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(\dirname($this->root));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'path separator' => ['sub/info.html'];
        yield 'backslash' => ['sub\\info.html'];
        yield 'parent segment' => ['..info.html'];
        yield 'dots inside' => ['a..b.html'];
        yield 'uppercase' => ['Info.html'];
        yield 'leading dot' => ['.info.html'];
        yield 'leading dash' => ['-info.html'];
        yield 'trailing dot' => ['info.html.'];
        yield 'no extension' => ['info'];
        yield 'extension outside the list' => ['info.txt'];
        yield 'executable extension' => ['info.php'];
        yield 'double extension' => ['info.html.php'];
        yield 'empty' => [''];
        yield 'con' => ['con.html'];
        yield 'nul' => ['nul.jpg'];
        yield 'prn' => ['prn.png'];
        yield 'aux' => ['aux.webp'];
        yield 'com1' => ['com1.png'];
        yield 'com9' => ['com9.html'];
        yield 'lpt1' => ['lpt1.jpg'];
        yield 'lpt9' => ['lpt9.html'];
        yield 'reserved with extra extension' => ['con.backup.html'];
        yield 'too long' => [str_repeat('a', 252).'.png'];
    }

    #[DataProvider('invalidNames')]
    public function testWriteRejectsInvalidNamesAndWritesNothing(string $name): void
    {
        $id = $this->linkedRecord();

        try {
            $this->writer()->write($id, $name, 'x');
            self::fail('The name must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->folderEntries());
    }

    #[DataProvider('invalidNames')]
    public function testInspectRejectsInvalidNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer()->inspect($this->linkedRecord(), $name);
    }

    public function testWriteCoverRejectsInvalidBaseNames(): void
    {
        $id = $this->linkedRecord();

        foreach (['../cover', 'con', 'Cover', 'cover.', 'a/b'] as $baseName) {
            try {
                $this->writer()->writeCover($id, $baseName);
                self::fail(\sprintf('"%s" must be rejected.', $baseName));
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->folderEntries());
            }
        }
    }

    public function testWriteRejectsContentsOverFiveMegabytes(): void
    {
        $id = $this->linkedRecord();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->writer()->write($id, 'info.html', str_repeat('a', RecordFolderWriter::MAX_BYTES + 1));
        } finally {
            self::assertSame([], $this->folderEntries());
        }
    }

    public function testWriteAcceptsContentsOfExactlyFiveMegabytes(): void
    {
        $this->writer()->write($this->linkedRecord(), 'info.html', str_repeat('a', RecordFolderWriter::MAX_BYTES));

        self::assertSame(RecordFolderWriter::MAX_BYTES, filesize($this->root.'/Record/info.html'));
    }

    public function testWriteCreatesTheFileRecordsItsHashAndLeavesNoTemporaryFile(): void
    {
        $id = $this->linkedRecord();

        $this->writer()->write($id, 'info.html', '<p>one</p>');

        self::assertSame('<p>one</p>', file_get_contents($this->root.'/Record/info.html'));
        self::assertSame(['info.html'], $this->folderEntries());
        self::assertSame([hash('sha256', '<p>one</p>')], $this->ledger('fake-vendor'));
    }

    public function testRewritingTheFileThePluginWroteItselfNeedsNoOverwrite(): void
    {
        $id = $this->linkedRecord();
        $this->writer()->write($id, 'info.html', 'one');

        $this->writer()->write($id, 'info.html', 'two');

        self::assertSame('two', file_get_contents($this->root.'/Record/info.html'));
        self::assertSame([hash('sha256', 'two')], $this->ledger('fake-vendor'));
    }

    public function testForeignFileIsKeptAndReported(): void
    {
        $id = $this->linkedRecord();
        file_put_contents($this->root.'/Record/info.html', 'mine');

        try {
            $this->writer()->write($id, 'info.html', 'theirs');
            self::fail('A foreign file must not be replaced.');
        } catch (ForeignFileException) {
        }

        self::assertSame('mine', file_get_contents($this->root.'/Record/info.html'));
        self::assertSame(['info.html'], $this->folderEntries());
        self::assertSame([], $this->ledger('fake-vendor'));
    }

    public function testFileChangedAfterTheWriteBecomesForeign(): void
    {
        $id = $this->linkedRecord();
        $this->writer()->write($id, 'info.html', 'one');
        file_put_contents($this->root.'/Record/info.html', 'edited by the user');

        $this->expectException(ForeignFileException::class);

        $this->writer()->write($id, 'info.html', 'two');
    }

    public function testFileAlreadyEqualToTheNewBytesIsAdoptedIntoTheLedger(): void
    {
        $id = $this->linkedRecord();
        file_put_contents($this->root.'/Record/info.html', 'same');

        $this->writer()->write($id, 'info.html', 'same');

        self::assertSame([hash('sha256', 'same')], $this->ledger('fake-vendor'));
        self::assertSame(RecordFolderFileState::Written, $this->writer()->inspect($id, 'info.html'));
    }

    public function testOverwriteReplacesAForeignFile(): void
    {
        $id = $this->linkedRecord();
        file_put_contents($this->root.'/Record/info.html', 'mine');

        $this->writer()->write($id, 'info.html', 'theirs', true);

        self::assertSame('theirs', file_get_contents($this->root.'/Record/info.html'));
        self::assertSame([hash('sha256', 'theirs')], $this->ledger('fake-vendor'));
    }

    public function testTheLedgerIsPerPlugin(): void
    {
        $id = $this->linkedRecord();
        $this->writer('fake-vendor')->write($id, 'info.html', 'one');

        $this->expectException(ForeignFileException::class);

        $this->writer('fake-vendor-two')->write($id, 'info.html', 'two');
    }

    public function testInspectReportsAbsentWrittenAndModified(): void
    {
        $id = $this->linkedRecord();
        $writer = $this->writer();

        self::assertSame(RecordFolderFileState::Absent, $writer->inspect($id, 'info.html'));

        $writer->write($id, 'info.html', 'one');
        self::assertSame(RecordFolderFileState::Written, $writer->inspect($id, 'info.html'));

        file_put_contents($this->root.'/Record/info.html', 'changed');
        self::assertSame(RecordFolderFileState::Modified, $writer->inspect($id, 'info.html'));

        file_put_contents($this->root.'/Record/other.html', 'no ledger');
        self::assertSame(RecordFolderFileState::Modified, $writer->inspect($id, 'other.html'));
    }

    public function testInspectDoesNotRelyOnSizeOrMtime(): void
    {
        $id = $this->linkedRecord();
        $this->writer()->write($id, 'info.html', 'aaaa');
        file_put_contents($this->root.'/Record/info.html', 'bbbb');
        touch($this->root.'/Record/info.html', 1000);

        self::assertSame(RecordFolderFileState::Modified, $this->writer()->inspect($id, 'info.html'));
    }

    public function testWriteCoverCopiesTheCoverUnderTheBaseNameWithItsRealExtension(): void
    {
        $id = $this->linkedRecord(cover: 'cover.webp');

        $name = $this->writer()->writeCover($id, 'cover');

        self::assertSame('cover.png', $name);
        self::assertSame(base64_decode(self::PNG, true), file_get_contents($this->root.'/Record/cover.png'));
        self::assertSame([hash('sha256', (string) base64_decode(self::PNG, true))], $this->ledger('fake-vendor'));
    }

    public function testWriteCoverReturnsNullWithoutACover(): void
    {
        $id = $this->linkedRecord();

        self::assertNull($this->writer()->writeCover($id, 'cover'));
        self::assertSame([], $this->folderEntries());
    }

    public function testWriteCoverReturnsNullForAnUnsupportedImageFormat(): void
    {
        $id = $this->linkedRecord(cover: 'cover.webp', coverBytes: 'GIF89a not really');

        self::assertNull($this->writer()->writeCover($id, 'cover'));
        self::assertSame([], $this->folderEntries());
    }

    public function testWriteCoverKeepsAForeignFileUnlessOverwritten(): void
    {
        $id = $this->linkedRecord(cover: 'cover.webp');
        file_put_contents($this->root.'/Record/cover.png', 'theirs');

        try {
            $this->writer()->writeCover($id, 'cover');
            self::fail('A foreign file must not be replaced.');
        } catch (ForeignFileException) {
        }
        self::assertSame('theirs', file_get_contents($this->root.'/Record/cover.png'));

        self::assertSame('cover.png', $this->writer()->writeCover($id, 'cover', true));
        self::assertSame(base64_decode(self::PNG, true), file_get_contents($this->root.'/Record/cover.png'));
    }

    public function testWriteCoverNeverLeavesTheMediaDirectory(): void
    {
        $id = $this->linkedRecord(cover: '../outside.png');
        file_put_contents($this->mediaDir.'/outside.png', base64_decode(self::PNG, true));

        self::assertNull($this->writer()->writeCover($id, 'cover'));
    }

    public function testExternalRStorageIsNotWritable(): void
    {
        $id = $this->linkedRecord(type: StorageType::ExternalR);

        $this->assertNotWritable($id);
    }

    public function testVideoStorageIsNotWritable(): void
    {
        $id = $this->linkedRecord(type: StorageType::Video, marker: false);

        $this->assertNotWritable($id);
    }

    public function testStorageWithoutAMarkerIsNotWritable(): void
    {
        $id = $this->linkedRecord(marker: false);

        $this->assertNotWritable($id);
    }

    public function testStorageWithAnotherStorageMarkerIsNotWritable(): void
    {
        $id = $this->linkedRecord(marker: false);
        file_put_contents($this->root.'/desktop.ini', "[AnimeDB]\nid=9999\n");

        $this->assertNotWritable($id);
    }

    public function testRecordFolderResolvingOutsideTheRootIsNotWritable(): void
    {
        $id = $this->linkedRecord(storagePath: 'Escape');
        mkdir(\dirname($this->root).'/elsewhere');
        symlink(\dirname($this->root).'/elsewhere', $this->root.'/Escape');

        try {
            $this->writer()->write($id, 'info.html', 'x');
            self::fail('A folder outside the storage root must be refused.');
        } catch (StorageNotWritableException) {
        }

        self::assertSame([], array_values(array_diff((array) scandir(\dirname($this->root).'/elsewhere'), ['.', '..'])));
    }

    public function testUnavailableStorageIsReportedAsUnavailable(): void
    {
        $id = $this->linkedRecord();
        $this->removeDirectory($this->root);

        $this->expectException(StorageUnavailableException::class);

        $this->writer()->write($id, 'info.html', 'x');
    }

    public function testRecordPointingAtASingleFileHasNoFolder(): void
    {
        $id = $this->linkedRecord(storagePath: 'movie.mkv');
        file_put_contents($this->root.'/movie.mkv', 'video');

        try {
            $this->writer()->write($id, 'info.html', 'x');
            self::fail('A single file has no folder.');
        } catch (RecordHasNoFolderException) {
        }

        self::assertSame(['Record', 'desktop.ini', 'movie.mkv'], array_values(array_diff((array) scandir($this->root), ['.', '..'])));
    }

    public function testRecordWithoutAStorageHasNoFolder(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Unlinked')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->expectException(RecordHasNoFolderException::class);

        $this->writer()->inspect(new AnimeId((int) $anime->id), 'info.html');
    }

    public function testLedgerRowsGoWithTheRecord(): void
    {
        $id = $this->linkedRecord();
        $this->writer()->write($id, 'info.html', 'one');
        $anime = $this->entityManager->find(MovieAnime::class, $id->value);
        self::assertNotNull($anime);

        $this->entityManager->remove($anime);
        $this->entityManager->flush();

        self::assertSame([], $this->ledger('fake-vendor'));
        self::assertFileExists($this->root.'/Record/info.html');
    }

    private function assertNotWritable(AnimeId $id): void
    {
        try {
            $this->writer()->write($id, 'info.html', 'x');
            self::fail('Writing must be refused.');
        } catch (StorageNotWritableException) {
        }

        self::assertSame([], $this->folderEntries());
    }

    private function writer(string $pluginId = 'fake-vendor'): RecordFolderWriter
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        return new RecordFolderWriter(new PluginId($pluginId), $registry, $this->markers, $this->mediaDir);
    }

    private function linkedRecord(StorageType $type = StorageType::Folder, bool $marker = true, string $storagePath = 'Record', ?string $cover = null, string|false $coverBytes = false): AnimeId
    {
        $storage = new Storage('Library', $this->root, $type);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        if ($marker) {
            $this->markers->reconcile($storage);
        }

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $anime->setStorage($storage)->setStoragePath($storagePath);
        $anime->setCover($cover);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = (int) $anime->id;

        if ($cover !== null) {
            @mkdir($this->mediaDir.'/'.$id, recursive: true);
            file_put_contents($this->mediaDir.'/'.$id.'/'.basename($cover), $coverBytes !== false ? $coverBytes : base64_decode(self::PNG, true));
        }

        return new AnimeId($id);
    }

    /** @return list<string> */
    private function folderEntries(): array
    {
        return array_values(array_filter((array) scandir($this->root.'/Record'), static fn (string|false $entry): bool => $entry !== false && $entry !== '.' && $entry !== '..'));
    }

    /** @return list<string> */
    private function ledger(string $pluginId): array
    {
        $rows = $this->entityManager->getRepository(RecordFolderFile::class)->findAll();

        return array_values(array_map(
            static fn (RecordFolderFile $row): string => $row->sha256,
            array_filter($rows, static fn (RecordFolderFile $row): bool => $row->getPluginId() === $pluginId),
        ));
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $this->removeDirectory($dir.'/'.$entry);
        }
        rmdir($dir);
    }
}
