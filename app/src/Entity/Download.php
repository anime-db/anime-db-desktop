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

namespace App\Entity;

use App\Entity\Enum\DownloadStatus;
use App\Entity\Exception\InvalidInfoHashException;
use Doctrine\ORM\Mapping as ORM;

/**
 * One (infoHash, anime) pairing row for the qBittorrent-backed download manager (issue #346).
 * qBittorrent itself is the durable store of the torrent's queue/progress/fast-resume data — this
 * table only maps a torrent back to the catalog entries waiting on it, N:M by design: a season
 * pack's single infoHash can pair with several Anime (one row each, {@see DownloadStatus} tracked
 * per row), and a re-download of an infoHash already known under a different Anime only adds a
 * row here, never re-enqueues the torrent (see QbittorrentDownloadService::enqueue()).
 *
 * $status is a single flag doubling as "download finished AND folder linked to the catalog
 * entry" (see markCompleted()) — this is what lets DownloadCompletionPoller tell, across
 * restarts, which pairs it has already emitted DownloadCompletedEvent for.
 */
#[ORM\Entity]
#[ORM\Table(name: 'downloads')]
#[ORM\UniqueConstraint(name: 'uniq_download_infohash_anime', columns: ['info_hash', 'anime_id'])]
class Download
{
    private const INFO_HASH_PATTERN = '/^[0-9a-f]{40}$/';

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

    public function isCompleted(): bool
    {
        return $this->status === DownloadStatus::Completed;
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
}
