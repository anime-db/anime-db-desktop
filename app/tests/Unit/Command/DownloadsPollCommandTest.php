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

use App\Command\DownloadsPollCommand;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;

final class DownloadsPollCommandTest extends TestCase
{
    public function testRunningTheCommandWithNoPendingDownloadsSucceedsWithoutAnyHttpCall(): void
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
        $entityManager = new EntityManager($connection, $config);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('No pending download should trigger an HTTP call.');
        });

        $jail = new DownloadFolderJail();
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, 'http://127.0.0.1:18080'),
            new DownloadRepository($entityManager),
            new AnimeDownloadLinker(new AnimeRepository($entityManager), $entityManager, $jail),
            new EventDispatcher(),
            $entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $tester = new CommandTester(new DownloadsPollCommand($poller));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
