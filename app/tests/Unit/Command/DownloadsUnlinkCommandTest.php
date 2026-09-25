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

use App\Command\DownloadsUnlinkCommand;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DownloadsUnlinkCommandTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $repository;
    private CommandTester $tester;

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
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new DownloadRepository($this->entityManager);
        $this->tester = new CommandTester(new DownloadsUnlinkCommand($this->repository));
    }

    private function persistAnime(string $title): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testUnlinkRemovesThePairingAndKeepsTheAnime(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH, $anime));

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
        $this->assertNotNull($this->entityManager->find(TvAnime::class, $anime->id));
    }

    public function testUnlinkedInfoHashCanBePairedWithAnotherAnime(): void
    {
        $anime = $this->persistAnime('Anime A');
        $other = $this->persistAnime('Anime B');
        $this->repository->save(new Download(self::HASH, $anime));

        $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);
        $this->repository->save(new Download(self::HASH, $other));

        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $other->id));
    }

    public function testMissingPairingFailsWithMessage(): void
    {
        $anime = $this->persistAnime('Anime A');

        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => (string) $anime->id]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('No pairing found', $this->tester->getDisplay());
    }

    public function testNonNumericAnimeIdFails(): void
    {
        $exit = $this->tester->execute(['info-hash' => self::HASH, 'anime-id' => 'abc']);

        $this->assertSame(Command::FAILURE, $exit);
    }
}
