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

namespace App\Tests\Unit\MessageHandler;

use AnimeDb\PluginContracts\SyncInterface;
use AnimeDb\PluginContracts\SyncItem;
use AnimeDb\PluginContracts\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Message\PushSyncMessage;
use App\MessageHandler\PushSyncMessageHandler;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the push handler loads the current entity by id, resolves the external id per
 * active sync plugin and forwards a SyncItem built from the anime's current watchStatus
 * (issue #214) — without a live plugin/network, both doubled here.
 */
final class PushSyncMessageHandlerTest extends TestCase
{
    private EntityManager $entityManager;
    private string $pluginsConfigPath;

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

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->pluginsConfigPath = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginsConfigPath, $this->pluginsConfigPath.'.tmp', $this->pluginsConfigPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testPushesToEveryActivePluginThatResolvesAnExternalId(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        $sync->expects($this->once())
            ->method('push')
            ->with($this->equalTo(new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop')));

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry);
        $handler(new PushSyncMessage($animeId));
    }

    public function testSkipsAPluginThatDoesNotRecognizeAnySource(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        file_put_contents($this->pluginsConfigPath, json_encode([
            'animedb-shikimori' => ['features' => ['sync' => true]],
        ]));

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn(null);
        $sync->expects($this->never())->method('push');

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry);
        $handler(new PushSyncMessage($animeId));
    }

    public function testDoesNothingWhenTheAnimeNoLongerExists(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('push');

        $registry = new SyncRegistry(
            ['animedb-shikimori' => $sync],
            new PluginsConfigStore($this->pluginsConfigPath),
        );

        $handler = new PushSyncMessageHandler($this->entityManager, $registry);
        $handler(new PushSyncMessage(999));
    }
}
