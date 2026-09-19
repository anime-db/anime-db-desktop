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

use App\Command\CatalogExportCommand;
use App\Service\Download\FreeSpaceProvider;
use App\Service\Export\CatalogExportService;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\WsPublisher;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogExportCommandTest extends TestCase
{
    private string $dbPath;
    private string $mediaDir;
    private string $destinationDir;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir().'/animedb-export-cmd-test-'.uniqid().'.db';
        $this->mediaDir = sys_get_temp_dir().'/animedb-export-cmd-test-media-'.uniqid();
        $this->destinationDir = sys_get_temp_dir().'/animedb-export-cmd-test-dest-'.uniqid();
        mkdir($this->mediaDir, 0o755, true);
        mkdir($this->destinationDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->mediaDir, $this->destinationDir] as $dir) {
            foreach ((array) glob($dir.'/*') as $file) {
                is_dir($file) ? null : @unlink($file);
            }
            @rmdir($dir);
        }
        @unlink($this->dbPath);
    }

    public function testReturnsSuccessAndPrintsTheArchivePathWhenTheExportSucceeds(): void
    {
        $tester = new CommandTester(new CatalogExportCommand($this->createService(\PHP_INT_MAX)));

        $exitCode = $tester->execute(['destination' => $this->destinationDir]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Catalog exported to', $tester->getDisplay());
    }

    public function testReturnsExitCodeTwoWhenTheDestinationVolumeDoesNotHaveEnoughFreeSpace(): void
    {
        $tester = new CommandTester(new CatalogExportCommand($this->createService(1)));

        $exitCode = $tester->execute(['destination' => $this->destinationDir]);

        $this->assertSame(2, $exitCode);
    }

    private function createService(int $freeBytes): CatalogExportService
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $connection->executeStatement('CREATE TABLE anime (id INTEGER PRIMARY KEY, title TEXT, cover TEXT)');
        $connection->executeStatement('CREATE TABLE anime_image (id INTEGER PRIMARY KEY, anime_id INTEGER, source TEXT)');
        $connection->insert('anime', ['id' => 1, 'title' => 'A']);

        $freeSpaceProvider = new class($freeBytes) implements FreeSpaceProvider {
            public function __construct(private readonly int $bytes)
            {
            }

            public function getFreeBytes(string $path): ?int
            {
                return $this->bytes;
            }
        };

        $pluginsRegistry = new InstalledPluginsRegistry(
            sys_get_temp_dir().'/animedb-export-cmd-test-plugins-does-not-exist',
            new PluginsConfigStore(sys_get_temp_dir().'/animedb-export-cmd-test-plugins-'.uniqid().'.json'),
            new NullLogger(),
        );

        return new CatalogExportService(
            $connection,
            $freeSpaceProvider,
            new WsPublisher(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $pluginsRegistry,
            new NullLogger(),
            $this->mediaDir,
            '2.0.0',
        );
    }
}
