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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\BackupController;
use App\Service\Backup\BackupListService;
use App\Service\Import\StagedImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * {@see StagedImportService} is final (no interface to mock against), so these tests back it
 * with a real temp directory rather than doubling it — same approach
 * {@see \App\Tests\Unit\Service\Import\CatalogStageServiceTest} already takes for the sibling
 * service that writes the marker this one reads.
 */
final class BackupControllerTest extends TestCase
{
    private string $importStagingDir;
    private string $backupsDir;

    protected function setUp(): void
    {
        $this->importStagingDir = sys_get_temp_dir().'/animedb-backup-controller-test-'.uniqid();
        $this->backupsDir = sys_get_temp_dir().'/animedb-backup-controller-test-backups-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->importStagingDir);
        $this->removeDirectory($this->backupsDir);
    }

    public function testIndexPassesNullStagedImportWhenNothingIsStaged(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', ['stagedImport' => null, 'backups' => []])
            ->willReturn('<html></html>');

        $response = $this->createController(twig: $twig)->index();

        self::assertInstanceOf(Response::class, $response);
    }

    public function testIndexPassesTheStagedImportMarkerWhenOneIsPresent(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', json_encode([
            'markerVersion' => 1,
            'stagedAt' => '2026-09-18T12:34:56Z',
            'sourceArchive' => 'catalog.zip',
        ]));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', $this->callback(function (array $params): bool {
                return $params['stagedImport'] !== null
                    && $params['stagedImport']->sourceArchive === 'catalog.zip'
                    && $params['stagedImport']->stagedAt->format('Y-m-d\TH:i:sP') === '2026-09-18T12:34:56+00:00';
            }))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->index();
    }

    public function testIndexPassesTheBackupSnapshotList(): void
    {
        mkdir($this->backupsDir, 0o755, true);
        file_put_contents($this->backupsDir.'/data-preimport-20260101-000000.db', 'x');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', $this->callback(function (array $params): bool {
                return \count($params['backups']) === 1
                    && $params['backups'][0]->name === 'data-preimport-20260101-000000.db'
                    && $params['backups'][0]->isPreImport;
            }))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->index();
    }

    public function testCancelImportRejectsAnInvalidCsrfToken(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', '{}');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);

        try {
            $this->createController(csrfTokenManager: $csrfTokenManager)->cancelImport(new Request());
            self::fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            // expected
        }

        self::assertDirectoryExists($this->importStagingDir);
    }

    public function testCancelImportRemovesStagingAndRedirectsBackToTheBackupPage(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', '{}');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/backup');

        $response = $this->createController(urlGenerator: $urlGenerator)->cancelImport(new Request());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/settings/backup', $response->getTargetUrl());
        self::assertDirectoryDoesNotExist($this->importStagingDir);
    }

    private function createController(
        ?Environment $twig = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): BackupController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new BackupController(
            $twig ?? $this->createStub(Environment::class),
            new StagedImportService($this->importStagingDir),
            new BackupListService($this->backupsDir),
            $csrfTokenManager,
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
        );
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

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
