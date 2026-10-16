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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\Filler\DescriptionSourceCandidates;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DescriptionSourceCandidatesTest extends TestCase
{
    private string $dir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/anime-description-candidates-'.uniqid();
        foreach (['animedb-shikimori' => 'Shikimori', 'animedb-mal' => 'MAL', 'animedb-worldart' => 'World-Art', 'animedb-anidb' => 'AniDB'] as $id => $name) {
            mkdir($this->dir.'/'.$id, recursive: true);
            file_put_contents($this->dir.'/'.$id.'/manifest.json', json_encode([
                'id' => $id,
                'name' => $name,
                'version' => '1.0.0',
                'type' => 'integration',
                'features' => ['filler' => $id !== 'animedb-worldart'],
                'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            ]));
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE anime_description (source VARCHAR(64) NULL)');
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    /** @param list<string> $fields */
    private function filler(array $fields): FillerInterface
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn($fields);

        return $filler;
    }

    private function candidates(AppSettingsProvider $settings): DescriptionSourceCandidates
    {
        $configPath = $this->dir.'/plugins.json';
        $installed = new InstalledPluginsRegistry($this->dir, new PluginsConfigStore($configPath), new NullLogger());
        $installed->reconcile();
        $fillers = [
            'animedb-shikimori' => $this->filler(['descriptions', 'genres']),
            'animedb-mal' => $this->filler(['genres']),
        ];

        return new DescriptionSourceCandidates(new FillerRegistry($fillers, new PluginsConfigStore($configPath)), $installed, $settings, $this->connection);
    }

    public function testListsActiveDescriptionFillersAndInstalledPluginsFoundInSource(): void
    {
        foreach ([null, 'manual', 'animedb-worldart', 'animedb-worldart', 'animedb-shikimori', 'animedb-removed'] as $source) {
            $this->connection->insert('anime_description', ['source' => $source]);
        }

        $list = $this->candidates(new AppSettingsProvider(new AppConfigStore($this->dir.'/config.json')))->list();

        // MAL has no 'descriptions' and no rows, the removed plugin is not installed, 'manual' is no plugin.
        $this->assertSame([
            ['id' => 'animedb-shikimori', 'name' => 'Shikimori'],
            ['id' => 'animedb-worldart', 'name' => 'World-Art'],
        ], $list);
    }

    public function testSavedPriorityComesFirstAndStaleIdsAreDropped(): void
    {
        $this->connection->insert('anime_description', ['source' => 'animedb-worldart']);
        $settings = new AppSettingsProvider(new AppConfigStore($this->dir.'/config.json'));
        $settings->setDescriptionSourcePriority(['animedb-worldart', 'animedb-gone', 'animedb-shikimori']);

        $list = $this->candidates($settings)->list();

        $this->assertSame(['animedb-worldart', 'animedb-shikimori'], array_column($list, 'id'));
    }
}
