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
use App\Service\Import\ImportedPluginsService;
use App\Service\Import\StagedImportService;
use App\Service\Import\V1\V1ImportReportStore;
use App\Service\Import\V1\V1ImportResult;
use App\Service\Market\PluginRegistryCache;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    private string $importRejectionPath;
    private string $backupsDir;
    private string $pluginsDir;
    private string $importAppliedPath;
    private string $importV1ReportPath;
    private string $marketRegistryCachePath;

    protected function setUp(): void
    {
        $this->importStagingDir = sys_get_temp_dir().'/animedb-backup-controller-test-'.uniqid();
        $this->importRejectionPath = sys_get_temp_dir().'/animedb-backup-controller-test-rejection-'.uniqid().'.json';
        $this->backupsDir = sys_get_temp_dir().'/animedb-backup-controller-test-backups-'.uniqid();
        $this->pluginsDir = sys_get_temp_dir().'/animedb-backup-controller-test-plugins-'.uniqid();
        $this->importAppliedPath = sys_get_temp_dir().'/animedb-backup-controller-test-applied-'.uniqid().'.json';
        $this->importV1ReportPath = sys_get_temp_dir().'/animedb-backup-controller-test-v1-report-'.uniqid().'.json';
        $this->marketRegistryCachePath = sys_get_temp_dir().'/animedb-backup-controller-test-market-cache-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->importStagingDir);
        @unlink($this->importRejectionPath);
        $this->removeDirectory($this->backupsDir);
        $this->removeDirectory($this->pluginsDir);
        @unlink($this->importAppliedPath);
        @unlink($this->importV1ReportPath);
        @unlink($this->marketRegistryCachePath);
    }

    public function testIndexPassesNullStagedImportWhenNothingIsStaged(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', ['stagedImport' => null, 'stagedImportRejectionReason' => null, 'backups' => [], 'importedPlugins' => [], 'importV1Report' => []])
            ->willReturn('<html></html>');

        $response = $this->createController(twig: $twig)->index();

        self::assertInstanceOf(Response::class, $response);
    }

    public function testIndexPassesTheRejectionReasonWhenAnImportWasRejected(): void
    {
        file_put_contents($this->importRejectionPath, json_encode(['reason' => 'incompatible_schema']));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', ['stagedImport' => null, 'stagedImportRejectionReason' => 'incompatible_schema', 'backups' => [], 'importedPlugins' => [], 'importV1Report' => []])
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->index();
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

    /**
     * Acceptance criterion 1/2 (issue #726): a not-yet-installed plugin from the imported
     * archive's manifest keeps the block visible and passes the plugin list through to the
     * template — see the twig fixture in {@see \App\Tests\Unit\Service\Import\ImportedPluginsServiceTest}
     * for the status resolution itself, which this controller only wires up.
     */
    public function testIndexPassesTheImportedPluginsListWhenANotInstalledPluginRemains(): void
    {
        file_put_contents($this->importAppliedPath, (string) json_encode(['plugins' => [['id' => 'animedb-shikimori']]]));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', $this->callback(function (array $params): bool {
                return \count($params['importedPlugins']) === 1
                    && $params['importedPlugins'][0]->id === 'animedb-shikimori';
            }))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->index();

        self::assertFileExists($this->importAppliedPath);
    }

    /**
     * Acceptance criterion 2 (issue #726): once every plugin the archive named is already
     * installed, there is nothing left worth showing — the block hides and the file that would
     * keep describing an import that has nothing left to say about is removed in this same GET.
     */
    public function testIndexHidesTheImportedPluginsBlockAndRemovesTheFileWhenNoneAreLeftToInstall(): void
    {
        mkdir($this->pluginsDir.'/animedb-shikimori', 0o755, true);
        file_put_contents($this->pluginsDir.'/animedb-shikimori/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        (new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->pluginsDir.'/plugins.json'), new NullLogger()))->reconcile();

        file_put_contents($this->importAppliedPath, (string) json_encode(['plugins' => [['id' => 'animedb-shikimori']]]));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', $this->callback(fn (array $params): bool => $params['importedPlugins'] === []))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->index();

        self::assertFileDoesNotExist($this->importAppliedPath);
    }

    public function testIndexPassesThePersistedV1ReportLinesToTheTemplate(): void
    {
        file_put_contents($this->importV1ReportPath, (string) json_encode(['animeCreated' => 3]));

        $translator = new \Symfony\Component\Translation\Translator('en');
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $translator->addResource('array', ['import_v1.report_created' => 'created %count%'], 'en');
        $expected = (new V1ImportResult(animeCreated: 3))->render($translator);
        self::assertSame('created 3', $expected[0]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', $this->callback(fn (array $params): bool => $params['importV1Report'] === $expected))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig, translator: $translator)->index();

        self::assertFileExists($this->importV1ReportPath);
    }

    public function testIndexDropsAnUnusableV1ReportFile(): void
    {
        file_put_contents($this->importV1ReportPath, '{broken');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/backup/index.html.twig', $this->callback(fn (array $params): bool => $params['importV1Report'] === []))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->index();

        self::assertFileDoesNotExist($this->importV1ReportPath);
    }

    public function testDismissImportV1ReportRejectsAnInvalidCsrfTokenAndKeepsTheFile(): void
    {
        file_put_contents($this->importV1ReportPath, '{"animeCreated":3}');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);

        try {
            $this->createController(csrfTokenManager: $csrfTokenManager)->dismissImportV1Report(new Request());
            self::fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            // expected
        }

        self::assertFileExists($this->importV1ReportPath);
    }

    public function testDismissImportV1ReportRemovesTheFileAndRedirects(): void
    {
        file_put_contents($this->importV1ReportPath, '{"animeCreated":3}');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/backup');

        $response = $this->createController(urlGenerator: $urlGenerator)->dismissImportV1Report(new Request());

        self::assertSame(303, $response->getStatusCode());
        self::assertFileDoesNotExist($this->importV1ReportPath);
    }

    public function testDismissImportedPluginsRejectsAnInvalidCsrfToken(): void
    {
        file_put_contents($this->importAppliedPath, '{"plugins":[]}');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);

        try {
            $this->createController(csrfTokenManager: $csrfTokenManager)->dismissImportedPlugins(new Request());
            self::fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException) {
            // expected
        }

        self::assertFileExists($this->importAppliedPath);
    }

    public function testDismissImportedPluginsRemovesTheFileAndRedirectsBackToTheBackupPage(): void
    {
        file_put_contents($this->importAppliedPath, '{"plugins":[]}');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/settings/backup');

        $response = $this->createController(urlGenerator: $urlGenerator)->dismissImportedPlugins(new Request());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/settings/backup', $response->getTargetUrl());
        self::assertFileDoesNotExist($this->importAppliedPath);
    }

    private function createController(
        ?Environment $twig = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?\Symfony\Component\Translation\Translator $translator = null,
    ): BackupController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        $importedPluginsService = new ImportedPluginsService(
            new PluginRegistryCache($this->marketRegistryCachePath, new NullLogger()),
            new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->pluginsDir.'/plugins.json'), new NullLogger()),
            $this->importAppliedPath,
        );

        return new BackupController(
            $twig ?? $this->createStub(Environment::class),
            new StagedImportService($this->importStagingDir, $this->importRejectionPath),
            new BackupListService($this->backupsDir),
            $importedPluginsService,
            new V1ImportReportStore($this->importV1ReportPath, new NullLogger()),
            $translator ?? new \Symfony\Component\Translation\Translator('en'),
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
