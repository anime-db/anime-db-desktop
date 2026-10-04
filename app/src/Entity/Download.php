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

namespace App\Entity;

use App\Entity\Enum\DownloadStatus;
use App\Entity\Exception\InvalidInfoHashException;
use Doctrine\ORM\Mapping as ORM;

/**
 * One (infoHash, anime) pairing row for the qBittorrent-backed download manager (issue #346).
 * qBittorrent itself is the durable store of the torrent's queue/progress/fast-resume data — this
 * table only maps a torrent back to the catalog entries waiting on it. One torrent backs exactly
 * one Anime: info_hash is UNIQUE, so linking it to a second Anime is rejected by the storage itself
 * (see QbittorrentDownloadService::enqueue()), not just by a check-then-act in the service.
 *
 * $status is a single flag doubling as "download finished AND folder linked to the catalog
 * entry" (see markCompleted()) — this is what lets DownloadCompletionPoller tell, across
 * restarts, which pairs it has already emitted DownloadCompletedEvent for.
 *
 * $storage/$storagePath (issue #837) are a snapshot, not a live value: the exact (storage, path)
 * pair {@see \App\Service\Download\AnimeDownloadLinker::link()} wrote onto the anime when this row
 * completed, recorded by {@see recordLinkedStorage()} in the same flush as markCompleted(). A row
 * completed before this snapshot existed keeps both NULL. {@see
 * \App\Service\Download\DownloadFolderPointer::releaseIfOwnedBy()} is the only reader: it compares
 * the anime's current pointer against this snapshot before deciding whether unlinking this row may
 * clear it — a NULL snapshot never matches, so a legacy row never clears the anime's pointer.
 *
 * $targetStorage (issue #851) is a DIFFERENT pointer than $storage above: it is the Storage this
 * row was enqueued INTO ({@see \App\Service\Download\QbittorrentDownloadService::enqueueTo()}
 * assigns it before the row is ever written), while $storage is the completion snapshot the
 * linker writes later. {@see \App\Service\Download\AnimeDownloadLinker::link()} reads
 * $targetStorage to know which Storage a completed torrent's content_path is relative to.
 * $failureReason was first written only by the migration that introduced it (issue #851,
 * "legacy_layout" for a pre-#851 Pending row with no target storage); markFailed() (issue #852)
 * is now the only other writer, one code per place the poller fails a row (see
 * DownloadsOverviewBuilder::failureReasonKey() for the full set).
 */
#[ORM\Entity]
#[ORM\Table(name: 'downloads')]
#[ORM\UniqueConstraint(name: 'uniq_download_infohash', columns: ['info_hash'])]
#[ORM\Index(name: 'IDX_DOWNLOAD_ANIME', fields: ['anime'])]
#[ORM\Index(name: 'IDX_DOWNLOAD_STORAGE', fields: ['storage'])]
#[ORM\Index(name: 'IDX_DOWNLOAD_TARGET_STORAGE', fields: ['targetStorage'])]
class Download
{
    private const INFO_HASH_PATTERN = '/^[0-9a-f]{40}\z/';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Lowercase 40-char hex BitTorrent v1 infohash — the canonical form qBittorrent's WebUI
     * reports torrents under, and what TorrentInfoHashResolver normalizes both magnet and
     * .torrent-file sources to before a row is ever created.
     */
    #[ORM\Column(name: 'info_hash', length: 40)]
    private string $infoHash;

    #[ORM\ManyToOne(targetEntity: Anime::class)]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Anime $anime;

    #[ORM\Column(length: 16, enumType: DownloadStatus::class)]
    private DownloadStatus $status;

    #[ORM\Column(name: 'date_add', type: 'unix_timestamp')]
    private \DateTimeImmutable $dateAdd;

    #[ORM\ManyToOne(targetEntity: Storage::class)]
    #[ORM\JoinColumn(name: 'storage_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Storage $storage = null;

    #[ORM\Column(name: 'storage_path', length: 1024, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\ManyToOne(targetEntity: Storage::class)]
    #[ORM\JoinColumn(name: 'target_storage_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Storage $targetStorage = null;

    #[ORM\Column(name: 'failure_reason', length: 32, nullable: true)]
    private ?string $failureReason = null;

    /**
     * How many times {@see \App\Service\Download\DownloadIncomingRelocator::tryMove()} has called
     * `torrents/setLocation` to move this row's torrent out of the storage's hidden incoming
     * directory (issue #852). Never reset: once it reaches the relocator's attempt limit with the
     * torrent still reporting a content_path under incoming, the row is failed for good — there is
     * no automatic retry (see app:downloads:retry, a follow-up issue).
     */
    #[ORM\Column(name: 'move_attempts', type: 'integer', options: ['default' => 0])]
    private int $moveAttempts = 0;

    /**
     * Doctrine's optimistic lock: every UPDATE checks this column and bumps it, failing with
     * {@see \Doctrine\ORM\OptimisticLockException} if another process already changed the row
     * since this one read it — same mechanism as {@see AnimePluginData::$version}. Guards two
     * directions of the same race between DownloadCompletionPoller and app:downloads:unlink
     * (issue #837): a poller flush() racing an unlink that already deleted the row fails loudly
     * here instead of silently resurrecting a row (and, via its Anime, a pointer) the operator
     * just removed; DownloadsUnlinkCommand reads this value and makes its own DELETE conditional
     * on it still matching, so a row the poller just completed is not deleted using stale data.
     */
    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(string $infoHash, Anime $anime)
    {
        if (preg_match(self::INFO_HASH_PATTERN, $infoHash) !== 1) {
            throw new InvalidInfoHashException(\sprintf('"%s" is not a 40-char lowercase hex infohash.', $infoHash));
        }

        $this->infoHash = $infoHash;
        $this->anime = $anime;
        $this->status = DownloadStatus::Pending;
        $this->dateAdd = new \DateTimeImmutable();
    }

    public function getInfoHash(): string
    {
        return $this->infoHash;
    }

    public function getAnime(): Anime
    {
        return $this->anime;
    }

    public function getStatus(): DownloadStatus
    {
        return $this->status;
    }

    public function getLinkedStorage(): ?Storage
    {
        return $this->storage;
    }

    public function getLinkedStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getTargetStorage(): ?Storage
    {
        return $this->targetStorage;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getMoveAttempts(): int
    {
        return $this->moveAttempts;
    }

    /**
     * Called once per `torrents/setLocation` request {@see
     * \App\Service\Download\DownloadIncomingRelocator::tryMove()} sends for this row's torrent.
     */
    public function incrementMoveAttempts(): void
    {
        ++$this->moveAttempts;
    }

    /**
     * Records which Storage {@see \App\Service\Download\QbittorrentDownloadService::enqueueTo()}
     * put this row's torrent into — called once, before the row is first persisted, never changed
     * afterwards.
     */
    public function assignTargetStorage(Storage $storage): void
    {
        $this->targetStorage = $storage;
    }

    /**
     * Records the (storage, relative path) pair {@see \App\Service\Download\AnimeDownloadLinker}
     * just wrote onto this row's anime, so a later unlink can tell whether the anime's pointer is
     * still exactly what this completion put there. Called from inside link() itself, in the same
     * flush() as markCompleted() (see class docblock) — never on its own.
     */
    public function recordLinkedStorage(Storage $storage, string $storagePath): void
    {
        $this->storage = $storage;
        $this->storagePath = $storagePath;
    }

    public function isCompleted(): bool
    {
        return $this->status === DownloadStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === DownloadStatus::Failed;
    }

    /**
     * Transitions Pending => Completed and reports whether it actually did so. The caller
     * (DownloadCompletionPoller) relies on the `false` result to skip re-linking/re-dispatching
     * DownloadCompletedEvent for a pair it already completed on a previous poll — this is the
     * whole idempotency guard for "emit exactly once across restarts".
     */
    public function markCompleted(): bool
    {
        if ($this->status === DownloadStatus::Completed) {
            return false;
        }

        $this->status = DownloadStatus::Completed;

        return true;
    }

    /**
     * Undoes an in-memory-only markCompleted() when the completion step that must accompany it
     * (linking the folder, see DownloadCompletionPoller) failed before either was ever flushed.
     * Only correct to call in that exact window — reverting an already-flushed Completed row
     * back to Pending would make it retry forever even though it already emitted its event.
     */
    public function revertToPending(): void
    {
        $this->status = DownloadStatus::Pending;
    }

    /**
     * Transitions Pending => Failed (issue #348: DownloadCompletionPoller found that a magnet's
     * size, once known, does not fit the downloads root's free space) and reports whether it
     * actually did so — same idempotency shape as markCompleted(), so a pair already marked
     * Failed on a previous poll is not re-paused/re-logged. $reason is stored as-is onto
     * $failureReason (issue #852): null leaves it unset, rendered as a generic failure by
     * DownloadsOverviewBuilder.
     */
    public function markFailed(?string $reason = null): bool
    {
        if ($this->status !== DownloadStatus::Pending) {
            return false;
        }

        $this->status = DownloadStatus::Failed;
        $this->failureReason = $reason;

        return true;
    }
}
