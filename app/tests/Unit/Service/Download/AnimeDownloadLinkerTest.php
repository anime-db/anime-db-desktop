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
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadFolderJail;
use App\Service\Exception\DownloadPathOutsideJailException;
use App\Service\Exception\DownloadStoragePathConflictException;
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
    private string $configPath;

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

        $this->configPath = sys_get_temp_dir().'/anime-download-linker-test-'.uniqid().'.json';
        file_put_contents($this->configPath, json_encode(['downloadsRoot' => self::ROOT]));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $this->linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testLinkSetsStorageAndRelativeStoragePath(): void
    {
        $anime = $this->persistAnime();

        $this->linker->link($anime, self::ROOT.'\\some-release');

        $this->assertNotNull($anime->getStorage());
        $this->assertSame(self::ROOT, $anime->getStorage()->getPath());
        $this->assertSame('some-release', $anime->getStoragePath());
    }

    public function testLinkReusesTheSameStorageRowForASecondAnime(): void
    {
        $first = $this->persistAnime();
        $second = $this->persistAnime();

        $this->linker->link($first, self::ROOT.'\\release-one');
        $this->linker->link($second, self::ROOT.'\\release-two');

        $this->assertNotNull($first->getStorage());
        $this->assertNotNull($second->getStorage());
        $this->assertSame($first->getStorage()->id, $second->getStorage()->id);
    }

    public function testLinkRejectsAPathOutsideTheDownloadsRoot(): void
    {
        $anime = $this->persistAnime();

        $this->expectException(DownloadPathOutsideJailException::class);

        $this->linker->link($anime, 'C:\\Users\\bob\\Documents\\secret');
    }

    public function testLinkRejectsAPairAlreadyHeldByAnotherAnimeWithoutWriting(): void
    {
        $holder = $this->persistAnime();
        $other = $this->persistAnime();
        $this->linker->link($holder, self::ROOT.'\\shared-pack');

        try {
            $this->linker->link($other, self::ROOT.'\\shared-pack');
            $this->fail('Expected DownloadStoragePathConflictException.');
        } catch (DownloadStoragePathConflictException $exception) {
            $this->assertSame($holder->id, $exception->occupyingAnimeId);
        }

        $this->assertNull($other->getStorage());
        $this->assertNull($other->getStoragePath());
    }

    public function testLinkingTheSameAnimeTwiceIsNotAConflict(): void
    {
        $anime = $this->persistAnime();

        $this->linker->link($anime, self::ROOT.'\\some-release');
        $this->linker->link($anime, self::ROOT.'\\some-release');

        $this->assertSame('some-release', $anime->getStoragePath());
    }
}
