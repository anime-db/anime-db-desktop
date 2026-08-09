<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Download;

use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use AnimeDb\PluginContracts\Download\DownloadTaskId;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Download;
use App\Repository\DownloadRepository;
use App\Service\Exception\DownloadPathOutsideJailException;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Detects finished torrents by POLLING qBittorrent's WebUI (GET /api/v2/torrents/info) — there is
 * no push/webhook from qbittorrent-nox — and, for each still-Pending (infoHash, anime) pairing
 * whose torrent just finished, links the folder to the catalog entry and dispatches
 * {@see DownloadCompletedEvent} exactly once (issue #346).
 *
 * Nothing in this class triggers poll() on a schedule — this app has no periodic-job mechanism
 * yet (see .claude-docs/gotchas.md); wiring poll() to actually run on an interval (a supervised
 * native-side loop, or symfony/scheduler) is deliberately left to a follow-up issue, same as
 * #345 was split from #356. What poll() itself guarantees does not depend on how often it is
 * called: idempotency comes entirely from Download::$status, persisted in the `downloads` table,
 * not from any in-memory state — calling poll() twice in a row, or after a full app restart,
 * never re-links or re-dispatches for a pair already marked Completed.
 *
 * markCompleted() + AnimeDownloadLinker::link() are flushed to the database BEFORE the event is
 * dispatched (never after): a crash between the two would at worst silently drop one event
 * (acceptable for a single-process desktop app with no event outbox), whereas flushing after
 * dispatch could re-dispatch the same event on the next poll if the process died before the
 * flush — and "at most once" is the wrong trade-off here, since the acceptance criterion is
 * "exactly once", not "at least once".
 *
 * completeDownload() isolates failures per (infoHash, anime) pair: AnimeDownloadLinker::link()
 * can throw {@see DownloadPathOutsideJailException} for a torrent qBittorrent itself reports as
 * finished but whose content_path was moved outside the downloads root (e.g. a "Set Location" in
 * qBittorrent's own WebUI) — a reachable state this class does not control. Left uncaught, that
 * would escape pollInfoHash()/poll() and, since findDistinctPendingInfoHashes() keeps returning
 * the same offending infoHash every run, permanently wedge every OTHER pending download queued
 * behind it. The pair that fails is logged and skipped instead; everything else in the batch
 * still gets linked and dispatched this run.
 *
 * failIfOutOfSpace() (issue #348) is the async half of the free-space precheck: a `.torrent`
 * file's size is known up front, so QbittorrentDownloadService rejects it synchronously before it
 * is ever added to qBittorrent, but a magnet's size is only known once qBittorrent has fetched
 * its metadata — this class is the only place already polling for exactly that. Once a torrent's
 * reported size is non-zero and does not fit the downloads root's free space, every pending
 * (infoHash, anime) row is marked Failed and the torrent paused — never a thrown exception, since
 * there is no calling UI context left by the time this runs.
 */
final class DownloadCompletionPoller
{
    /**
     * A torrent is done exactly when progress has reached 1.0 AND its state is none of these —
     * "checking*" covers every qBittorrent state prefixed "checking" (e.g. checkingUP,
     * checkingResumeData), not just one literal value.
     */
    private const array NOT_DONE_STATES = ['moving', 'error', 'missingFiles'];
    private const string CHECKING_STATE_PREFIX = 'checking';

    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly DownloadRepository $downloads,
        private readonly AnimeDownloadLinker $linker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly FreeSpaceChecker $freeSpaceChecker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function poll(): void
    {
        foreach ($this->downloads->findDistinctPendingInfoHashes() as $infoHash) {
            $this->pollInfoHash($infoHash);
        }
    }

    private function pollInfoHash(string $infoHash): void
    {
        $torrent = $this->client->getTorrentsInfo($infoHash)[0] ?? null;
        if ($torrent === null) {
            return;
        }

        // A magnet's size is unknown until qBittorrent has fetched its metadata (issue #348) —
        // this is where that free-space check catches up, once size becomes known, for as long
        // as the torrent is still short of Completed. A torrent that already reached Completed
        // necessarily wrote all of its bytes, so it is not re-checked here.
        if (!$this->isComplete($torrent)) {
            $this->failIfOutOfSpace($torrent, $infoHash);

            return;
        }

        $contentPath = $torrent['content_path'] ?? $torrent['save_path'] ?? null;
        if (!\is_string($contentPath) || $contentPath === '') {
            return;
        }

        foreach ($this->downloads->findPendingByInfoHash($infoHash) as $download) {
            $this->completeDownload($download, $contentPath, $infoHash);
        }
    }

    /**
     * @param array<string, mixed> $torrent
     */
    private function failIfOutOfSpace(array $torrent, string $infoHash): void
    {
        $size = (int) ($torrent['size'] ?? 0);
        if ($size <= 0 || $this->freeSpaceChecker->hasEnoughFreeSpace($size)) {
            return;
        }

        $this->client->pause($infoHash);

        $pending = $this->downloads->findPendingByInfoHash($infoHash);
        foreach ($pending as $download) {
            $download->markFailed();
        }
        if ($pending !== []) {
            $this->entityManager->flush();
        }

        $this->logger->warning('Paused download: not enough free disk space for its reported size.', [
            'infoHash' => $infoHash,
            'size' => $size,
        ]);
    }

    private function completeDownload(Download $download, string $contentPath, string $infoHash): void
    {
        if (!$download->markCompleted()) {
            return;
        }

        $anime = $download->getAnime();

        try {
            $this->linker->link($anime, $contentPath);
        } catch (DownloadPathOutsideJailException $exception) {
            // markCompleted() above only touched in-memory state — link() never reached its own
            // flush(), so nothing was persisted yet. Revert it so this Download does not sit
            // dirty as Completed in the EntityManager's unit of work and get flushed as a side
            // effect of some unrelated download completing later in this same poll() run.
            $download->revertToPending();
            $this->logger->warning('Skipping download completion: content_path is outside the configured downloads root.', [
                'infoHash' => $infoHash,
                'contentPath' => $contentPath,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once it has a Download row pointing at it.');
        $this->eventDispatcher->dispatch(new DownloadCompletedEvent(new AnimeId($animeId), new DownloadTaskId($infoHash)));
    }

    /**
     * @param array<string, mixed> $torrent
     */
    private function isComplete(array $torrent): bool
    {
        $progress = (float) ($torrent['progress'] ?? 0);
        $state = (string) ($torrent['state'] ?? '');

        if ($progress < 1.0) {
            return false;
        }

        if (str_starts_with($state, self::CHECKING_STATE_PREFIX)) {
            return false;
        }

        return !\in_array($state, self::NOT_DONE_STATES, true);
    }
}
