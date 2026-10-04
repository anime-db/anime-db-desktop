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

use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Storage;
use App\Repository\DownloadRepository;
use App\Service\Storage\StorageMarkerService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the "Downloads" page's (issue #854) row view-models — both the initial SSR render and
 * the live-update JSON endpoint share this one method, so the two never drift apart on what
 * "Pending with progress 1.0" or "no torrent in the client" renders as.
 *
 * Deliberately takes the qBittorrent torrents list as a plain array rather than calling
 * {@see \App\Service\Qbittorrent\QbittorrentClient} itself: the caller makes exactly one
 * `torrents/info` request (or none, once, if qBittorrent is down) and this class only ever
 * consumes that single result, which is what keeps the live-update endpoint's "one request per
 * call" invariant mechanical rather than something this class could accidentally break.
 *
 * $qbittorrentAvailable distinguishes "asked qBittorrent and this infoHash was not in the
 * response" (a download's torrent really is gone from the client) from "qBittorrent itself is
 * unreachable" (nothing can be said about any torrent one way or the other) — only the former
 * ever renders "missing from client"/"seeding stopped"/an orphan row; the latter degrades to the
 * plain DB-only status text with every live field left null.
 */
final class DownloadsOverviewBuilder
{
    public function __construct(
        private readonly DownloadRepository $downloads,
        private readonly StorageMarkerService $storageMarker,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $torrents raw `torrents/info` response; pass an empty
     *                                             array (with $qbittorrentAvailable false) when
     *                                             qBittorrent could not be reached at all
     *
     * @return array{rows: list<array<string, mixed>>, orphans: list<array<string, mixed>>}
     */
    public function build(array $torrents, bool $qbittorrentAvailable): array
    {
        $torrentsByInfoHashV1 = [];
        foreach ($torrents as $torrent) {
            $infoHashV1 = (string) ($torrent['infohash_v1'] ?? '');
            if ($infoHashV1 !== '') {
                $torrentsByInfoHashV1[$infoHashV1] = $torrent;
            }
        }

        // Keyed by storage path, not id: most Pending rows share the same targetStorage, and
        // reading its desktop.ini marker is a disk I/O (potentially slow on a disconnected
        // network/removable drive) that this endpoint's 2s budget can't afford to pay once per
        // row on every 2-second poll tick.
        $markerIdCache = [];

        $rows = [];
        $claimedInfoHashes = [];
        foreach ($this->downloads->findAllOrderedByDateAddDesc() as $download) {
            $infoHash = $download->getInfoHash();
            $torrent = $torrentsByInfoHashV1[$infoHash] ?? null;
            if ($torrent !== null) {
                $claimedInfoHashes[$infoHash] = true;
            }

            $rows[] = $this->buildRow($download, $torrent, $qbittorrentAvailable && $torrent === null, $markerIdCache);
        }

        $orphans = [];
        if ($qbittorrentAvailable) {
            foreach ($torrentsByInfoHashV1 as $infoHash => $torrent) {
                if (!isset($claimedInfoHashes[$infoHash])) {
                    $orphans[] = $this->buildOrphanRow($infoHash, $torrent);
                }
            }
        }

        return ['rows' => $rows, 'orphans' => $orphans];
    }

    /**
     * @param ?array<string, mixed> $torrent
     * @param array<string, ?int>   $markerIdCache keyed by storage path, shared across the whole
     *                                             build() call (see its own comment)
     *
     * @return array<string, mixed>
     */
    private function buildRow(Download $download, ?array $torrent, bool $torrentKnownMissing, array &$markerIdCache): array
    {
        $anime = $download->getAnime();
        $targetStorage = $download->getTargetStorage();

        return [
            'infoHash' => $download->getInfoHash(),
            'hasCard' => true,
            'animeId' => $anime->id,
            'animeUrl' => $anime->id !== null ? $this->urlGenerator->generate('anime_show', ['id' => $anime->id]) : null,
            'displayName' => $torrent['name'] ?? $anime->getTitle(),
            'coreStatus' => $download->getStatus()->value,
            'statusText' => $this->statusText($download, $torrent, $torrentKnownMissing, $targetStorage, $markerIdCache),
            'targetStorageName' => $targetStorage?->getName(),
        ] + $this->liveFields($torrent);
    }

    /**
     * @param array<string, mixed> $torrent
     *
     * @return array<string, mixed>
     */
    private function buildOrphanRow(string $infoHash, array $torrent): array
    {
        return [
            'infoHash' => $infoHash,
            'hasCard' => false,
            'animeId' => null,
            'animeUrl' => null,
            'displayName' => (string) ($torrent['name'] ?? $infoHash),
            'coreStatus' => null,
            'statusText' => $this->translator->trans('downloads.status_no_card'),
            'targetStorageName' => null,
        ] + $this->liveFields($torrent);
    }

    /**
     * @param ?array<string, mixed> $torrent
     *
     * @return array<string, mixed>
     */
    private function liveFields(?array $torrent): array
    {
        if ($torrent === null) {
            return [
                'sizeText' => null,
                'progressText' => null,
                'downloadSpeedText' => null,
                'uploadSpeedText' => null,
                'etaText' => null,
                'state' => null,
                'peersText' => null,
            ];
        }

        $size = (int) ($torrent['size'] ?? 0);
        $progress = (float) ($torrent['progress'] ?? 0);
        $eta = (int) ($torrent['eta'] ?? 0);
        $numSeeds = (int) ($torrent['num_seeds'] ?? 0);
        $numLeechs = (int) ($torrent['num_leechs'] ?? 0);

        return [
            'sizeText' => $this->formatBytes($size),
            'progressText' => \sprintf('%d%%', (int) round($progress * 100)),
            'downloadSpeedText' => $this->formatSpeed((int) ($torrent['dlspeed'] ?? 0)),
            'uploadSpeedText' => $this->formatSpeed((int) ($torrent['upspeed'] ?? 0)),
            // qBittorrent reports 8640000 (100 days) as its "unknown/never" ETA sentinel.
            'etaText' => $eta > 0 && $eta < 8640000 ? $this->formatDuration($eta) : null,
            'state' => (string) ($torrent['state'] ?? ''),
            'peersText' => \sprintf('%d/%d', $numSeeds, $numLeechs),
        ];
    }

    /**
     * @param ?array<string, mixed> $torrent
     * @param array<string, ?int>   $markerIdCache
     */
    private function statusText(Download $download, ?array $torrent, bool $torrentKnownMissing, ?Storage $targetStorage, array &$markerIdCache): string
    {
        $status = $download->getStatus();

        if ($status === DownloadStatus::Completed) {
            return $this->translator->trans($torrentKnownMissing ? 'downloads.status_seeding_stopped' : 'downloads.status_completed');
        }

        if ($torrentKnownMissing) {
            return $this->translator->trans('downloads.status_missing_from_client');
        }

        if ($status === DownloadStatus::Failed) {
            return $this->translator->trans($this->failureReasonKey($download->getFailureReason()));
        }

        // Pending from here on.
        if ($targetStorage !== null && $this->readMarkerIdCached($targetStorage, $markerIdCache) !== $targetStorage->id) {
            return $this->translator->trans('downloads.status_storage_unavailable');
        }

        if ($torrent !== null && (float) ($torrent['progress'] ?? 0) >= 1.0) {
            return $this->translator->trans('downloads.status_linking');
        }

        return $this->translator->trans('downloads.status_pending');
    }

    /** @param array<string, ?int> $markerIdCache */
    private function readMarkerIdCached(Storage $targetStorage, array &$markerIdCache): ?int
    {
        $path = $targetStorage->getPath();
        if (!\array_key_exists($path, $markerIdCache)) {
            $markerIdCache[$path] = $this->storageMarker->readMarkerId($path);
        }

        return $markerIdCache[$path];
    }

    private function failureReasonKey(?string $failureReason): string
    {
        return match ($failureReason) {
            'disk_space' => 'downloads.status_failed_disk_space',
            'storage_conflict' => 'downloads.status_failed_storage_conflict',
            'name_conflict' => 'downloads.status_failed_name_conflict',
            'move_failed' => 'downloads.status_failed_move_failed',
            'legacy_layout' => 'downloads.status_failed_legacy_layout',
            'unexpected_layout' => 'downloads.status_failed_unexpected_layout',
            default => 'downloads.status_failed_generic',
        };
    }

    /**
     * Unit text and the fractional part's decimal separator both come from the translation
     * catalog/{@see \NumberFormatter} rather than being hardcoded ASCII — issue #854's acceptance
     * criteria requires every UI string to exist in both ru and en, and "700.0 MB" with a literal
     * dot reads as English regardless of which locale's catalog the rest of the page uses.
     */
    private function formatBytes(int $bytes): string
    {
        $unitKeys = ['downloads.unit_b', 'downloads.unit_kb', 'downloads.unit_mb', 'downloads.unit_gb', 'downloads.unit_tb'];
        $value = (float) max(0, $bytes);

        $unitIndex = 0;
        while ($value >= 1024.0 && $unitIndex < \count($unitKeys) - 1) {
            $value /= 1024.0;
            ++$unitIndex;
        }

        $decimals = $unitIndex === 0 ? 0 : 1;
        $formatter = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return $this->translator->trans($unitKeys[$unitIndex], ['%value%' => $formatter->format($value)]);
    }

    private function formatSpeed(int $bytesPerSecond): string
    {
        return $this->translator->trans('downloads.speed', ['%value%' => $this->formatBytes($bytesPerSecond)]);
    }

    private function formatDuration(int $seconds): string
    {
        $hours = \intdiv($seconds, 3600);
        $minutes = \intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return $hours > 0 ? \sprintf('%d:%02d:%02d', $hours, $minutes, $secs) : \sprintf('%d:%02d', $minutes, $secs);
    }
}
