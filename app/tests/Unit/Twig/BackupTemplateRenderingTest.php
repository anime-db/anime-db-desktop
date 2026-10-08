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

namespace App\Tests\Unit\Twig;

use App\Service\Backup\BackupSnapshot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class BackupTemplateRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(): void
    {
        $request = Request::create('/settings/backup');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testRendersTheEmptyStateWithoutErrorsAcceptanceCriterion7(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', ['stagedImport' => null, 'stagedImportRejectionReason' => null, 'backups' => [], 'importedPlugins' => []]);

        self::assertStringContainsString('id="settings-backup-snapshots-empty"', $html);
        self::assertStringNotContainsString('id="settings-backup-snapshots-list"', $html);
    }

    public function testRendersRoutineAndPreImportSnapshotsWithARestoreButtonEach(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', [
            'stagedImport' => null,
            'stagedImportRejectionReason' => null,
            'backups' => [
                new BackupSnapshot('data-preimport-20260102-093000.db', new \DateTimeImmutable('2026-01-02 09:30:00'), 250 * 1024, true),
                new BackupSnapshot('data-1.2.3-20260101-120000.db', new \DateTimeImmutable('2026-01-01 12:00:00'), 100, false),
            ],
            'importedPlugins' => [],
        ]);

        self::assertStringNotContainsString('id="settings-backup-snapshots-empty"', $html);
        self::assertSame(2, substr_count($html, 'settings-backup-restore-button'));
        self::assertStringContainsString('data-backup-name="data-preimport-20260102-093000.db"', $html);
        self::assertStringContainsString('data-backup-name="data-1.2.3-20260101-120000.db"', $html);
        self::assertStringContainsString('2026-01-02 09:30:00', $html);
    }

    // Issue #706: the startup decision step can reject a staged import before FrankenPHP ever
    // starts, deleting import-staging/ entirely — this banner is the only way the user finds out
    // why, so it must show whenever a reason was recorded and no staged import is currently
    // pending (the two are mutually exclusive: a rejection always clears the staging directory).
    public function testRendersTheRejectionBannerWhenAnImportWasRejectedAndNoneIsCurrentlyStaged(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', [
            'stagedImport' => null,
            'stagedImportRejectionReason' => 'incompatible_schema',
            'backups' => [],
            'importedPlugins' => [],
        ]);

        self::assertStringContainsString('id="settings-backup-staged-import-rejected-banner"', $html);
        self::assertStringNotContainsString('id="settings-backup-staged-import-banner"', $html);
    }

    // Issue #710: a staged import that was applied and then rolled back must be reported as such,
    // not as the invalid_marker reason staged-import.js#decide() would otherwise (wrongly)
    // attribute a marker-less import-staging/ left behind by that rollback to.
    public function testRendersTheRollbackReasonRatherThanInvalidMarker(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', [
            'stagedImport' => null,
            'stagedImportRejectionReason' => 'import_rolled_back',
            'backups' => [],
            'importedPlugins' => [],
        ]);

        self::assertStringContainsString('rolled back', $html);
        self::assertStringNotContainsString('marker file', $html);
    }

    // A currently-pending staged import takes precedence over a stale rejection reason left over
    // from a previous, unrelated staging attempt — showing both would be confusing, and the
    // pending one is the actionable state.
    public function testDoesNotRenderTheRejectionBannerWhenAStagedImportIsAlreadyPending(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', [
            'stagedImport' => new \App\Service\Import\StagedImportMarker(new \DateTimeImmutable('2026-09-18 12:34:56'), 'catalog.zip'),
            'stagedImportRejectionReason' => 'incompatible_schema',
            'backups' => [],
            'importedPlugins' => [],
        ]);

        self::assertStringContainsString('id="settings-backup-staged-import-banner"', $html);
        self::assertStringNotContainsString('id="settings-backup-staged-import-rejected-banner"', $html);
    }

    // Issue #726: the imported-plugins block renders one line per plugin with its resolved
    // status, and stays hidden entirely when there is nothing to show.
    public function testRendersOneLinePerImportedPluginWithItsStatus(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', [
            'stagedImport' => null,
            'stagedImportRejectionReason' => null,
            'backups' => [],
            'importedPlugins' => [
                new \App\Service\Import\ImportedPlugin('animedb-shikimori', 'Shikimori', \App\Service\Import\ImportedPluginStatus::AvailableInMarket),
            ],
        ]);

        self::assertStringContainsString('id="settings-backup-imported-plugins-section"', $html);
        self::assertStringContainsString('Shikimori', $html);
        self::assertStringContainsString('/settings/backup/import/plugins/dismiss', $html);
    }

    public function testDoesNotRenderTheImportedPluginsBlockWhenThereIsNothingToShow(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/backup/index.html.twig', [
            'stagedImport' => null,
            'stagedImportRejectionReason' => null,
            'backups' => [],
            'importedPlugins' => [],
        ]);

        self::assertStringNotContainsString('id="settings-backup-imported-plugins-section"', $html);
    }

    public function testRendersTheImportV1ReportBlockOnlyWhenThereAreLines(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $context = ['stagedImport' => null, 'stagedImportRejectionReason' => null, 'backups' => [], 'importedPlugins' => []];

        $html = $twig->render('settings/backup/index.html.twig', $context + ['importV1Report' => ['Entries created: <5>']]);
        self::assertStringContainsString('id="settings-backup-import-v1-report-section"', $html);
        self::assertStringContainsString('Entries created: &lt;5&gt;', $html);
        self::assertStringContainsString('/settings/backup/import/v1-report/dismiss', $html);

        $html = $twig->render('settings/backup/index.html.twig', $context + ['importV1Report' => []]);
        self::assertStringNotContainsString('id="settings-backup-import-v1-report-section"', $html);
    }
}
