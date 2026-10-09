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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Service\Storage\Scan\ScanRun;
use App\Service\Storage\Scan\ScanRunStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Acceptance (issue #834): storage/scan_progress.html.twig renders through the real container
 * (csrf_token(), path(), the moved `data-control="storage-scan"` section) rather than only being
 * exercised through StorageControllerTest's mocked Twig — that suite pins which template/params
 * the controller passes, this one pins that the template itself is valid and reacts to `started`.
 */
final class StorageScanProgressTemplateRenderingTest extends KernelTestCase
{
    private function storage(): Storage
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 7);

        return $storage;
    }

    private function pushRequestWithSession(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testStartedRendersTheLiveScanSectionNotTheNotStartedPrompt(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/scan_progress.html.twig', ['storage' => $this->storage(), 'started' => true, 'latestRun' => null, 'failedRun' => null]);

        $this->assertStringContainsString('data-control="storage-scan"', $html);
        $this->assertStringContainsString('data-storage-id="7"', $html);
        $this->assertStringNotContainsString('storage_scan_progress.not_started', $html);
    }

    public function testNotStartedRendersThePromptNotTheScanSection(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/scan_progress.html.twig', ['storage' => $this->storage(), 'started' => false, 'latestRun' => null, 'failedRun' => null]);

        $this->assertStringNotContainsString('data-control="storage-scan"', $html);
        $this->assertStringContainsString('action="/storage/7/scan"', $html);
    }

    private function journalRun(ScanRunStatus $status, ?string $error = null): ScanRun
    {
        return new ScanRun(3, 7, new \DateTimeImmutable('@1000'), new \DateTimeImmutable('@1060'), $status, $error, ['NeedsManualEntry' => 2], []);
    }

    public function testNotStartedWithAJournalRunRendersItsResultsInsteadOfTheNotStartedText(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/scan_progress.html.twig', ['storage' => $this->storage(), 'started' => false, 'latestRun' => $this->journalRun(ScanRunStatus::Done), 'failedRun' => null]);

        $this->assertStringContainsString('data-control="storage-scan"', $html);
        $this->assertStringContainsString('data-items-url="/storage/7/scans/3/items"', $html);
        $this->assertStringNotContainsString('storage_scan_progress.not_started', $html);
        $this->assertStringContainsString('action="/storage/7/scan"', $html);
    }

    public function testAFailedRunShowsItsErrorAndNoResultsSection(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/_scan_run_results.html.twig', ['storage' => $this->storage(), 'run' => $this->journalRun(ScanRunStatus::Failed, 'boom')]);

        $this->assertStringContainsString('boom', $html);
        $this->assertStringNotContainsString('data-control="storage-scan"', $html);
    }

    public function testTheJournalPagesRender(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $log = $twig->render('storage/scan_log.html.twig', [
            'storage' => $this->storage(),
            'runs' => [$this->journalRun(ScanRunStatus::Done), $this->journalRun(ScanRunStatus::Interrupted)],
            'groupLabelKeys' => \App\Controller\StorageScanLogController::GROUP_LABEL_KEYS,
        ]);
        $this->assertStringContainsString('href="/storage/7/scans/3"', $log);

        $page = $twig->render('storage/scan_run.html.twig', ['storage' => $this->storage(), 'run' => $this->journalRun(ScanRunStatus::Done)]);
        $this->assertStringContainsString('data-items-url="/storage/7/scans/3/items"', $page);
    }
}
