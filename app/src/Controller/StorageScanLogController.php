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

namespace App\Controller;

use App\Entity\Storage;
use App\Service\Storage\Scan\ScanItemResolver;
use App\Service\Storage\Scan\ScanRun;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The storage scan journal (issue #998): the list of the last runs of a storage, one run's
 * results, and the JSON of that run's items which `storage-scan.js` renders — the very renderer
 * that shows a live scan, fed from here instead of the `scan.done` event.
 *
 * A run is addressed through its storage: a run that belongs to another storage is a 404, not a
 * quiet look at someone else's results.
 */
final class StorageScanLogController
{
    /** @var array<string, string> ScanItemType name => translation key of its group heading */
    public const array GROUP_LABEL_KEYS = [
        'Updated' => 'storage_list.group_updated',
        'FilesMissing' => 'storage_list.group_files_missing',
        'AutoLinked' => 'storage_list.group_auto_linked',
        'NeedsManualEntry' => 'storage_list.group_needs_manual_entry',
        'NeedsConfirmation' => 'storage_list.group_needs_confirmation',
        'Conflict' => 'storage_list.group_conflict',
        'Error' => 'storage_list.group_error',
    ];

    public function __construct(
        private readonly ScanRunJournal $journal,
        private readonly ScanItemResolver $resolver,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/storage/{id}/scans', name: 'storage_scans', methods: ['GET'])]
    public function index(Storage $storage): Response
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its scan journal can be shown.');

        return new Response($this->twig->render('storage/scan_log.html.twig', [
            'storage' => $storage,
            'runs' => $this->journal->findRecent($storageId),
            'groupLabelKeys' => self::GROUP_LABEL_KEYS,
        ]));
    }

    #[Route('/storage/{id}/scans/{run}', name: 'storage_scan_run', requirements: ['run' => '\d+'], methods: ['GET'])]
    public function show(Storage $storage, int $run): Response
    {
        $scanRun = $this->requireRun($storage, $run);

        return new Response($this->twig->render('storage/scan_run.html.twig', [
            'storage' => $storage,
            'run' => $scanRun,
        ]));
    }

    #[Route('/storage/{id}/scans/{run}/items', name: 'storage_scan_run_items', requirements: ['run' => '\d+'], methods: ['GET'])]
    public function items(Storage $storage, int $run): JsonResponse
    {
        $scanRun = $this->requireRun($storage, $run);

        return new JsonResponse([
            'run' => ['id' => $scanRun->id, 'status' => $scanRun->status->value],
            // Only the newest Done run is actionable: a confirmation addresses (storage, folder), not a run.
            'latest' => $scanRun->status === ScanRunStatus::Done && $this->journal->isLatestDone($scanRun->storageId, $scanRun->id),
            'items' => $this->resolver->annotate($scanRun->storageId, $scanRun->items),
        ]);
    }

    private function requireRun(Storage $storage, int $runId): ScanRun
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its scan journal can be shown.');

        return $this->journal->find($storageId, $runId) ?? throw new NotFoundHttpException(\sprintf('Scan run #%d not found.', $runId));
    }
}
