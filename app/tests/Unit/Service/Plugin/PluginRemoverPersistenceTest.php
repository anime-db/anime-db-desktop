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

namespace App\Tests\Unit\Service\Plugin;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\AnimePluginData;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression coverage for issue #225: removing an installed plugin ({@see PluginRemover::remove()})
 * must delete only the plugin's directory and its {@see InstalledPluginsRegistry} entry, never the
 * catalog data it accumulated in `anime_plugin_data` (#299) or `anime_external_id` (#297) —
 * neither table has a foreign key on plugin_id (see those entities' docblocks), specifically so a
 * later reinstall of the same plugin id re-links to what it already knew instead of starting over.
 *
 * Uses a real in-memory SQLite connection (same rationale as PluginDataStoreTest): the point is to
 * prove PluginRemover's actual scope against real rows, not to restate what its docblock already
 * claims.
 */
final class PluginRemoverPersistenceTest extends TestCase
{
    private string $rootDir;
    private string $pluginsDir;
    private InstalledPluginsRegistry $registry;
    private Connection $connection;
    private EntityManager $entityManager;
    private int $animeId;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-plugin-remover-persistence-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );

        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $ormConfig = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $ormConfig->enableNativeLazyObjects(true);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $ormConfig);
        $this->entityManager = new EntityManager($this->connection, $ormConfig);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->assertNotNull($anime->id);
        $this->animeId = $anime->id;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testRemoveLeavesThePluginsAccumulatedCatalogDataInPlace(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $anime = $this->entityManager->find(MovieAnime::class, $this->animeId);
        $this->assertNotNull($anime);
        $this->entityManager->persist(new AnimePluginData($anime, $pluginId, ['mal_id' => 1]));
        $anime->rememberExternalId($pluginId, 'mal-1');
        $this->entityManager->flush();

        $this->installFixture((string) $pluginId);
        $this->registry->reconcile();
        $this->assertTrue($this->registry->has($pluginId));

        (new PluginRemover($this->registry, new \App\Service\Plugin\PluginCacheDirectories(sys_get_temp_dir().'/anime-plugin-cache-unused', new NullLogger())))->remove($pluginId);

        $this->assertDirectoryDoesNotExist($this->pluginsDir.'/'.$pluginId);
        $this->assertFalse($this->registry->has($pluginId));

        $pluginDataCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM anime_plugin_data WHERE anime_id = ? AND plugin_id = ?',
            [$this->animeId, (string) $pluginId],
        );
        $this->assertSame(1, (int) $pluginDataCount);

        $externalIdCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM anime_external_id WHERE anime_id = ? AND plugin_id = ?',
            [$this->animeId, (string) $pluginId],
        );
        $this->assertSame(1, (int) $externalIdCount);
    }

    private function installFixture(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
