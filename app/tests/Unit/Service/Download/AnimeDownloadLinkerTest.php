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

namespace App\Tests\Unit\Service\Download;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadFolderJail;
use App\Service\Exception\DownloadPathOutsideJailException;
use App\Service\Exception\DownloadStoragePathConflictException;
use App\Service\Exception\DownloadTargetStorageMissingException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class AnimeDownloadLinkerTest extends TestCase
{
    private const string ROOT = 'C:\\Users\\bob\\Downloads';

    private EntityManager $entityManager;
    private AnimeDownloadLinker $linker;
    private Storage $storage;

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

        $this->storage = new Storage('AnimeDB', self::ROOT, StorageType::Folder);
        $this->entityManager->persist($this->storage);
        $this->entityManager->flush();

        $jail = new DownloadFolderJail();
        $this->linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function persistDownload(string $infoHash, TvAnime $anime): Download
    {
        $download = new Download($infoHash, $anime);
        $download->assignTargetStorage($this->storage);
        $this->entityManager->persist($download);
        $this->entityManager->flush();

        return $download;
    }

    public function testLinkSetsStorageAndRelativeStoragePath(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);

        $this->linker->link($download, self::ROOT.'\\some-release');

        $this->assertNotNull($anime->getStorage());
        $this->assertSame(self::ROOT, $anime->getStorage()->getPath());
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    public function testLinkRecordsTheSameSnapshotOnTheDownload(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);

        $this->linker->link($download, self::ROOT.'\\some-release');

        $this->assertNotNull($download->getLinkedStorage());
        $this->assertSame($anime->getStorage()?->id, $download->getLinkedStorage()->id);
        $this->assertSame('some-release', $download->getLinkedStoragePath());
    }

    public function testLinkReusesTheSameStorageRowForASecondAnime(): void
    {
        $first = $this->persistAnime();
        $second = $this->persistAnime();
        $firstDownload = $this->persistDownload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $first);
        $secondDownload = $this->persistDownload('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $second);

        $this->linker->link($firstDownload, self::ROOT.'\\release-one');
        $this->linker->link($secondDownload, self::ROOT.'\\release-two');

        $this->assertNotNull($first->getStorage());
        $this->assertNotNull($second->getStorage());
        $this->assertSame($first->getStorage()->id, $second->getStorage()->id);
    }

    public function testLinkRejectsAPathOutsideTheDownloadsRoot(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);

        $this->expectException(DownloadPathOutsideJailException::class);

        $this->linker->link($download, 'C:\\Users\\bob\\Documents\\secret');
    }

    public function testLinkRejectsAPairAlreadyHeldByAnotherAnimeWithoutWriting(): void
    {
        $holder = $this->persistAnime();
        $other = $this->persistAnime();
        $holderDownload = $this->persistDownload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $holder);
        $otherDownload = $this->persistDownload('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $other);
        $this->linker->link($holderDownload, self::ROOT.'\\shared-pack');

        try {
            $this->linker->link($otherDownload, self::ROOT.'\\shared-pack');
            $this->fail('Expected DownloadStoragePathConflictException.');
        } catch (DownloadStoragePathConflictException $exception) {
            $this->assertSame($holder->id, $exception->occupyingAnimeId);
        }

        $this->assertNull($other->getStorage());
        $this->assertNull($other->getStoragePath());
        $this->assertNull($otherDownload->getLinkedStorage());
        $this->assertNull($otherDownload->getLinkedStoragePath());
    }

    public function testLinkingTheSameAnimeTwiceIsNotAConflict(): void
    {
        $anime = $this->persistAnime();
        $download = $this->persistDownload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);

        $this->linker->link($download, self::ROOT.'\\some-release');
        $this->linker->link($download, self::ROOT.'\\some-release');

        $this->assertSame('some-release', $anime->getStoragePath());
    }

    /**
     * Issue #852: the relative path used to be cut using the RAW, unnormalized root's length —
     * for a UNC root like "\\nas\anime", {@see DownloadFolderJail}'s own
     * lexical normalize() collapses the leading "\\\\" while resolving $contentPath, which shifts
     * every character after it and silently truncates the start of the resulting relative path
     * (observed in production as "how S1" instead of "How S1"). Cutting by the SAME normalized
     * root's length instead keeps both sides aligned regardless of what normalize() dropped.
     */
    public function testLinkComputesTheRelativePathCorrectlyForAUncRoot(): void
    {
        $storage = new Storage('NAS', '\\\\nas\\anime', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $anime = $this->persistAnime();
        $download = new Download('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);
        $download->assignTargetStorage($storage);
        $this->entityManager->persist($download);
        $this->entityManager->flush();

        $this->linker->link($download, '\\\\nas\\anime\\How S1');

        $this->assertSame('How S1', $anime->getStoragePath());
    }

    /**
     * Same fix as above, for a root entered with a trailing separator and in different case than
     * qBittorrent later echoes back in content_path — neither changes how many characters
     * normalize() produces for the root, so the cut stays correct either way.
     */
    public function testLinkComputesTheRelativePathCorrectlyForARootWithATrailingSeparatorAndDifferentCase(): void
    {
        $storage = new Storage('Mixed', 'd:\\anime\\', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $anime = $this->persistAnime();
        $download = new Download('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);
        $download->assignTargetStorage($storage);
        $this->entityManager->persist($download);
        $this->entityManager->flush();

        $this->linker->link($download, 'D:\\Anime\\Release');

        $this->assertSame('Release', $anime->getStoragePath());
    }

    /**
     * Reachable without any malformed row: target_storage_id is ON DELETE SET NULL (see Download
     * entity), so a Pending download whose Storage was deleted while still in flight loses its
     * target storage this way, not by ever having been invalid.
     */
    public function testLinkThrowsADedicatedExceptionWhenTheTargetStorageIsMissing(): void
    {
        $anime = $this->persistAnime();
        $download = new Download('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime);
        $this->entityManager->persist($download);
        $this->entityManager->flush();

        try {
            $this->linker->link($download, self::ROOT.'\\some-release');
            $this->fail('Expected DownloadTargetStorageMissingException.');
        } catch (DownloadTargetStorageMissingException $exception) {
            $this->assertSame($download->getInfoHash(), $exception->infoHash);
        }

        $this->assertNull($anime->getStorage());
        $this->assertNull($anime->getStoragePath());
    }
}
