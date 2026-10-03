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

use App\Service\Download\DownloadsOverviewBuilder;
use App\Service\Exception\QbittorrentClientException;
use App\Service\Qbittorrent\QbittorrentClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON counterpart of {@see DownloadsController}'s page (issue #854), polled by
 * app/assets/js/downloads-list.js to keep the page's live fields (progress, speed, ETA, client
 * state) current without a full reload. Injected with its own {@see QbittorrentClient} instance
 * (`app.qbittorrent.status_client` in services.yaml), bound to a 2s-timeout HTTP client rather
 * than the 5s/15s one the download-completion poller uses: a stuck qBittorrent must not tie up a
 * FrankenPHP worker thread for the longer window on every 2-second poll tick.
 *
 * Makes exactly one `torrents/info` request per call, regardless of how many `downloads` rows
 * exist — {@see DownloadsOverviewBuilder::build()} takes that single result and does the
 * per-row matching itself.
 *
 * Never a 500: a qBittorrent failure here answers 200 with `qbittorrentAvailable: false`, the
 * same degradation {@see DownloadsController} falls back to for the full page.
 */
final class DownloadsStatusController
{
    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly DownloadsOverviewBuilder $overviewBuilder,
    ) {
    }

    #[Route('/downloads/status', name: 'downloads_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        [$torrents, $qbittorrentAvailable] = $this->fetchTorrents();
        $overview = $this->overviewBuilder->build($torrents, $qbittorrentAvailable);

        return new JsonResponse([
            'qbittorrentAvailable' => $qbittorrentAvailable,
            'rows' => $overview['rows'],
            'orphans' => $overview['orphans'],
        ]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function fetchTorrents(): array
    {
        try {
            return [$this->client->getTorrentsInfo(), true];
        } catch (QbittorrentClientException) {
            return [[], false];
        }
    }
}
