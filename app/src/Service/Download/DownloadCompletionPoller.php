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

namespace App\Service\Download;

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use AnimeDb\PluginContracts\Download\DownloadTaskId;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Download;
use App\Repository\DownloadRepository;
use App\Service\Exception\DownloadPathOutsideJailException;
use App\Service\Exception\DownloadStoragePathConflictException;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Detects finished torrents by POLLING qBittorrent's WebUI (GET /api/v2/torrents/info) — there is
 * no push/webhook from qbittorrent-nox — and, for each still-Pending (infoHash, anime) pairing
 * whose torrent just finished, links the folder to the catalog entry and dispatches
 * {@see DownloadCompletedEvent} exactly once (issue #346), alongside {@see AnimeFilesChangedEvent}
 * with {@see FilesChangeReason::DownloadFinished} (issue #703/#684, часть 3).
 *
 * Nothing in this class triggers poll() itself (issue #685): App\Command\DownloadsPollCommand and
 * App\MessageHandler\PollDownloadsMessageHandler are the only callers, invoked respectively once
 * at app startup (native/supervisor/downloads-poll.js) and on every tick of
 * App\Scheduler\DownloadsPollSchedule for as long as the app stays open. What poll() itself
 * guarantees does not depend on how often it is called or how many of those triggers land at
 * once: idempotency comes entirely from Download::$status, persisted in the `downloads` table,
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
 * fetchTorrentsByInfoHashV1() (issue #843) makes at most one `torrents/info?tag=` request per
 * poll() pass — none at all when there are no still-Pending rows — rather than one `hashes=<v1>`
 * request per pending infoHash: qBittorrent 5.x (libtorrent 2) identifies a hybrid v1+v2 torrent
 * by its truncated v2 hash, which a `hashes=<v1>` filter never matches, so that torrent would
 * never be found this way. Matching instead happens in-process against each torrent's
 * `infohash_v1` field, which is populated for every torrent regardless of libtorrent's chosen id.
 * Commands aimed at a specific torrent (failIfOutOfSpace()'s stop()) likewise use that torrent's
 * own `hash` from the response, not this app's v1 infoHash, for the same reason.
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
 * poll() isolates failures per infoHash as well: any \Throwable from one torrent is logged (with
 * its infoHash and exception class) and the pass moves on to the next one, so a single bad
 * torrent cannot wedge the rest. If the exception left the EntityManager closed (a failed
 * flush()), the pass stops with a separate log entry instead — nothing is thrown out of poll().
 *
 * failIfOutOfSpace() (issue #348) is the async half of the free-space precheck: a `.torrent`
 * file's size is known up front, so QbittorrentDownloadService rejects it synchronously before it
 * is ever added to qBittorrent, but a magnet's size is only known once qBittorrent has fetched
 * its metadata — this class is the only place already polling for exactly that. It compares free
 * space against the torrent's REMAINING bytes (`amount_left`), not its total size: free space on
 * the downloads root keeps shrinking as this very torrent writes to it, so comparing against the
 * full size would effectively demand ~2x the torrent's size in free space and false-positive on a
 * healthy, still-downloading torrent well before it finishes. Once a torrent's reported size is
 * non-zero and its remaining bytes do not fit the downloads root's free space, every pending
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

    /**
     * infoHashes this process has already logged a "missing from qBittorrent" warning for — kept
     * for the life of this instance (a long-running Messenger worker, see class docblock) so the
     * warning fires once per hash rather than once per 5-minute tick (issue #843). Never removed:
     * if the torrent reappears and then goes missing again, that is not re-warned either.
     *
     * @var array<string, true>
     */
    private array $warnedAboutMissingInfoHashes = [];

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
        $infoHashes = $this->downloads->findDistinctPendingInfoHashes();
        if ($infoHashes === []) {
            return;
        }

        $torrentsByInfoHashV1 = $this->fetchTorrentsByInfoHashV1();
        $total = \count($infoHashes);

        foreach ($infoHashes as $index => $infoHash) {
            try {
                $this->pollInfoHash($infoHash, $torrentsByInfoHashV1[$infoHash] ?? null);
            } catch (\Throwable $exception) {
                $this->logger->error('Polling a download failed; continuing with the remaining ones.', [
                    'infoHash' => $infoHash,
                    'exceptionClass' => $exception::class,
                    'exception' => $exception,
                ]);

                // A failed flush() closes the EntityManager for good — every later hash would
                // fail on it too, so stop the pass instead of throwing; still-Pending rows are
                // picked up again on the next tick.
                if (!$this->entityManager->isOpen()) {
                    $this->logger->error('EntityManager is closed; aborting the poll pass.', [
                        'infoHash' => $infoHash,
                        'remaining' => $total - $index - 1,
                    ]);

                    return;
                }
            }
        }
    }

    /**
     * One `torrents/info?tag=` request per poll pass for every torrent this app has added,
     * keyed by `infohash_v1` (issue #843) — not `hashes=<v1>` per pending download, since
     * qBittorrent 5.x (libtorrent 2) identifies a hybrid v1+v2 torrent by its truncated v2 hash
     * (see class docblock's "Why" in the issue), which never matches the v1 hash this app tracks.
     * A torrent with an empty `infohash_v1` (not one of ours, or a stray entry without it) is
     * skipped: it cannot match any pending row.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchTorrentsByInfoHashV1(): array
    {
        $byInfoHashV1 = [];
        foreach ($this->client->getTorrentsInfo(QbittorrentClient::TAG) as $torrent) {
            $infoHashV1 = (string) ($torrent['infohash_v1'] ?? '');
            if ($infoHashV1 === '') {
                continue;
            }

            $byInfoHashV1[$infoHashV1] = $torrent;
        }

        return $byInfoHashV1;
    }

    /**
     * @param array<string, mixed>|null $torrent
     */
    private function pollInfoHash(string $infoHash, ?array $torrent): void
    {
        if ($torrent === null) {
            // Reachable without anything being wrong: a human removed the torrent (or just its
            // tag) in qBittorrent's own WebUI (see class docblock). The row's status is left
            // alone rather than failed — it is not this poller's place to decide that a torrent
            // someone removed by hand is never coming back.
            if (!isset($this->warnedAboutMissingInfoHashes[$infoHash])) {
                $this->warnedAboutMissingInfoHashes[$infoHash] = true;
                $this->logger->warning('Pending download has no matching torrent in qBittorrent (removed, or its tag was removed); status left unchanged.', [
                    'infoHash' => $infoHash,
                ]);
            }

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
        if ($size <= 0) {
            return;
        }

        // Compare against what is still left to write, not the torrent's total size — free space
        // on the downloads root shrinks as this same torrent downloads, so a full-size comparison
        // would false-positive on a healthy torrent partway through (see class docblock).
        $amountLeft = (int) ($torrent['amount_left'] ?? $size);
        if ($this->freeSpaceChecker->hasEnoughFreeSpace($amountLeft)) {
            return;
        }

        // Addressed by qBittorrent's own "hash" (its torrent id), not this app's v1 infoHash
        // (issue #843): a hybrid torrent's v1 infoHash is not an id qBittorrent recognizes for
        // commands, and "stop" on an unknown id answers 200 while silently doing nothing.
        $this->client->stop((string) ($torrent['hash'] ?? ''));

        $pending = $this->downloads->findPendingByInfoHash($infoHash);
        foreach ($pending as $download) {
            $download->markFailed();
        }
        if ($pending !== []) {
            $this->entityManager->flush();
        }

        $this->logger->warning('Paused download: not enough free disk space for its remaining bytes.', [
            'infoHash' => $infoHash,
            'size' => $size,
            'amountLeft' => $amountLeft,
        ]);
    }

    private function completeDownload(Download $download, string $contentPath, string $infoHash): void
    {
        if (!$download->markCompleted()) {
            return;
        }

        $anime = $download->getAnime();

        try {
            $this->linker->link($download, $contentPath);
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
        } catch (DownloadStoragePathConflictException $exception) {
            // Unlike the jail failure, this never resolves itself: the torrent's content_path is
            // fixed and the occupant keeps the pair, so reverting to Pending would retry (and log)
            // on every poll forever. link() threw before touching the entity or flushing, so undo
            // the in-memory Completed and persist a terminal Failed instead.
            $download->revertToPending();
            $download->markFailed();
            $this->entityManager->flush();
            $this->logger->warning(\sprintf(
                'Failing download completion: the content path is already linked to anime #%d. To free it: '
                .'if anime #%d got this folder from another download of its own, run app:downloads:unlink '
                .'<that download\'s hash> %d (it clears the pointer only if it still matches that download\'s '
                .'snapshot); otherwise there is no command for this yet and anime #%d\'s folder pointer has to '
                .'be cleared by hand. Then run app:downloads:unlink %s %d to remove this failed pairing and '
                .'enqueue the download again.',
                $exception->occupyingAnimeId,
                $exception->occupyingAnimeId,
                $exception->occupyingAnimeId,
                $exception->occupyingAnimeId,
                $infoHash,
                $anime->id,
            ), [
                'infoHash' => $infoHash,
                'contentPath' => $contentPath,
                'occupyingAnimeId' => $exception->occupyingAnimeId,
            ]);

            return;
        } catch (\Throwable $exception) {
            // Same window as above for any other failure (e.g. a DBAL error before link()'s
            // flush()): poll() now continues past a failed hash, so a dirty Completed row would
            // otherwise be flushed by the next hash's flush() with no link and no events.
            $download->revertToPending();

            throw $exception;
        }

        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once it has a Download row pointing at it.');
        $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($animeId), FilesChangeReason::DownloadFinished));
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
