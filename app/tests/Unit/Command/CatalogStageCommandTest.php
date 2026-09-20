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

use App\Command\CatalogStageCommand;
use App\Service\Import\CatalogStageService;
use App\Service\WsPublisher;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class CatalogStageCommandTest extends TestCase
{
    private string $fixturesDir;
    private string $importStagingDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir().'/animedb-stage-cmd-test-'.uniqid();
        $this->importStagingDir = sys_get_temp_dir().'/animedb-stage-cmd-test-staging-'.uniqid();
        mkdir($this->fixturesDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
        $this->removeDirectory($this->importStagingDir);
    }

    public function testReturnsSuccessAndPrintsTheTranslatedMessageWhenStagingSucceeds(): void
    {
        $archivePath = $this->buildValidArchive();

        $tester = new CommandTester(new CatalogStageCommand($this->createService(), $this->createTranslator()));
        $exitCode = $tester->execute(['archive' => $archivePath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('staged at', $tester->getDisplay());
        $this->assertStringContainsString($this->importStagingDir, $tester->getDisplay());
    }

    public function testReturnsFailureAndPrintsTheTranslatedMessageWhenTheArchiveIsInvalid(): void
    {
        $archivePath = $this->fixturesDir.'/corrupt.zip';
        file_put_contents($archivePath, 'not a zip file');

        $tester = new CommandTester(new CatalogStageCommand($this->createService(), $this->createTranslator()));
        $exitCode = $tester->execute(['archive' => $archivePath]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Unable to read catalog archive', $tester->getDisplay());
    }

    private function buildValidArchive(): string
    {
        $dbPath = $this->fixturesDir.'/source.db';
        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->exec('CREATE TABLE anime (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO anime (id, title) VALUES (1, 'A')");
        $pdo = null;

        $archivePath = $this->fixturesDir.'/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($archivePath, \ZipArchive::CREATE);
        $zip->addFile($dbPath, 'data.db');
        $zip->addFromString('manifest.json', json_encode([
            'formatVersion' => 1,
            'counts' => ['anime' => 1, 'mediaFiles' => 0],
        ], \JSON_THROW_ON_ERROR));
        $zip->close();

        return $archivePath;
    }

    private function createService(): CatalogStageService
    {
        return new CatalogStageService(
            new WsPublisher(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            new NullLogger(),
            $this->importStagingDir,
        );
    }

    private function createTranslator(): Translator
    {
        $translationsDir = \dirname(__DIR__, 3).'/translations';

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $translationsDir.'/messages.en.yaml', 'en');

        return $translator;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
