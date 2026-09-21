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
        $html = $twig->render('settings/backup/index.html.twig', ['stagedImport' => null, 'backups' => []]);

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
            'backups' => [
                new BackupSnapshot('data-preimport-20260102-093000.db', new \DateTimeImmutable('2026-01-02 09:30:00'), 250 * 1024, true),
                new BackupSnapshot('data-1.2.3-20260101-120000.db', new \DateTimeImmutable('2026-01-01 12:00:00'), 100, false),
            ],
        ]);

        self::assertStringNotContainsString('id="settings-backup-snapshots-empty"', $html);
        self::assertSame(2, substr_count($html, 'settings-backup-restore-button'));
        self::assertStringContainsString('data-backup-name="data-preimport-20260102-093000.db"', $html);
        self::assertStringContainsString('data-backup-name="data-1.2.3-20260101-120000.db"', $html);
        self::assertStringContainsString('2026-01-02 09:30:00', $html);
    }
}
