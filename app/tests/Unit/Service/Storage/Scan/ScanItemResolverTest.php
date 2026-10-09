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

namespace App\Tests\Unit\Service\Storage\Scan;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\Storage\Scan\ScanItemResolver;
use App\Service\Storage\Scan\ScanRun;
use App\Service\Storage\Scan\ScanRunStatus;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * The storage path below does not exist on disk on purpose: resolving must come from the links of
 * the records alone, never from the filesystem.
 */
final class ScanItemResolverTest extends TestCase
{
    private EntityManager $entityManager;
    private Storage $storage;
    private ScanItemResolver $resolver;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->storage = new Storage('Main', '/nonexistent/anime-db-storage', StorageType::Folder);
        $this->entityManager->persist($this->storage);
        $this->entityManager->flush();

        $this->resolver = new ScanItemResolver(new AnimeRepository($this->entityManager));
    }

    /** @param array<string, mixed> $item */
    private function resolvedOf(array $item): ?bool
    {
        return $this->resolver->annotate($this->storage->id ?? 0, [$item])[0]['resolved'];
    }

    private function link(string $title, ?string $storagePath, ?Storage $storage = null): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        if ($storagePath !== null) {
            $anime->setStorage($storage ?? $this->storage)->setStoragePath($storagePath);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedWhenFreeTypes(): iterable
    {
        yield 'confirmation' => ['NeedsConfirmation'];
        yield 'manual entry' => ['NeedsManualEntry'];
        yield 'conflict' => ['Conflict'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvedWhenFreeTypes')]
    public function testAnItemNeedingADecisionIsResolvedOnceTheFolderPairIsHeld(string $type): void
    {
        $item = ['type' => $type, 'storage_path' => 'Trigun'];

        $this->assertFalse($this->resolvedOf($item));

        $this->link('Trigun', 'Trigun');

        $this->assertTrue($this->resolvedOf($item));
    }

    public function testThePairIsComparedIgnoringCaseAndATrailingSeparator(): void
    {
        $this->link('Foo', 'foo\\');

        $this->assertTrue($this->resolvedOf(['type' => 'NeedsManualEntry', 'storage_path' => 'Foo']));
        $this->assertTrue($this->resolvedOf(['type' => 'NeedsManualEntry', 'storage_path' => 'FOO/']));
    }

    public function testAFolderHeldInAnotherStorageDoesNotResolveTheItem(): void
    {
        $other = new Storage('Other', '/nonexistent/other', StorageType::Folder);
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->link('Trigun', 'Trigun', $other);

        $this->assertFalse($this->resolvedOf(['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun']));
    }

    public function testFilesMissingIsResolvedOnceTheRecordNoLongerHoldsThePair(): void
    {
        $anime = $this->link('Bleach', 'Bleach');
        $item = ['type' => 'FilesMissing', 'storage_path' => 'Bleach', 'anime' => ['id' => $anime->id, 'title' => 'Bleach']];

        $this->assertFalse($this->resolvedOf($item));

        $anime->setStorage(null)->setStoragePath(null);
        $this->entityManager->flush();

        $this->assertTrue($this->resolvedOf($item));
    }

    public function testFilesMissingIsResolvedWhenTheRecordIsGone(): void
    {
        $anime = $this->link('Bleach', 'Bleach');
        $item = ['type' => 'FilesMissing', 'storage_path' => 'Bleach', 'anime' => ['id' => $anime->id]];
        $this->entityManager->remove($anime);
        $this->entityManager->flush();

        $this->assertTrue($this->resolvedOf($item));
    }

    public function testFilesMissingStaysOpenWhileTheRecordHoldsADifferentSpellingOfThePair(): void
    {
        $anime = $this->link('Bleach', 'bleach\\');
        $item = ['type' => 'FilesMissing', 'storage_path' => 'Bleach', 'anime' => ['id' => $anime->id]];

        $this->assertFalse($this->resolvedOf($item));
    }

    public function testFilesMissingIsNotResolvedByAnotherRecordHoldingTheName(): void
    {
        $gone = $this->link('Old', null);
        $this->link('New', 'Bleach');

        $this->assertTrue($this->resolvedOf(['type' => 'FilesMissing', 'storage_path' => 'Bleach', 'anime' => ['id' => $gone->id]]));
    }

    public function testErrorAndAutoLinkedHaveNoState(): void
    {
        $this->link('Trigun', 'Trigun');

        $this->assertNull($this->resolvedOf(['type' => 'Error', 'storage_path' => 'Trigun']));
        $this->assertNull($this->resolvedOf(['type' => 'AutoLinked', 'storage_path' => 'Trigun']));
    }

    public function testAnItemWithMissingKeysDoesNotBreakTheComputation(): void
    {
        $this->assertNull($this->resolvedOf(['type' => 'NeedsConfirmation']));
        $this->assertNull($this->resolvedOf([]));
        // No record id to look at: the pair alone decides, and nobody holds it.
        $this->assertTrue($this->resolvedOf(['type' => 'FilesMissing', 'storage_path' => 'X']));
    }

    public function testNeedsDecisionCountCountsOnlyUnresolvedItemsOfTheDecisionTypes(): void
    {
        $this->link('Trigun', 'Trigun');
        $kept = $this->link('Bleach', 'Bleach');

        $run = new ScanRun(1, $this->storage->id ?? 0, new \DateTimeImmutable(), new \DateTimeImmutable(), ScanRunStatus::Done, null, [], [
            ['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun'],
            ['type' => 'NeedsManualEntry', 'storage_path' => 'Monster'],
            ['type' => 'NeedsConfirmation', 'storage_path' => 'Berserk'],
            ['type' => 'Conflict', 'storage_path' => 'Gantz'],
            ['type' => 'FilesMissing', 'storage_path' => 'Bleach', 'anime' => ['id' => $kept->id]],
            ['type' => 'Error', 'storage_path' => 'Broken'],
            ['type' => 'AutoLinked', 'storage_path' => 'Auto'],
        ]);

        $this->assertSame(4, $this->resolver->needsDecisionCount($run));
    }

    public function testResolvingNeverReadsTheFilesystem(): void
    {
        $this->assertDirectoryDoesNotExist((string) $this->storage->getPath());

        $this->assertFalse($this->resolvedOf(['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun']));
    }
}
