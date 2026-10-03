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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * "Downloads" page (issue #854): a view-only listing of every `downloads` row, enriched with one
 * `torrents/info` call to qBittorrent, plus any torrent the client knows about that has no row of
 * its own (see {@see DownloadsOverviewBuilder} for how the two are merged). Pausing, resuming,
 * retrying or deleting a download is out of scope here — issue #856.
 *
 * A qBittorrent request failure never turns into a 500: index() still renders the page with
 * $qbittorrentAvailable false, which the template turns into a banner plus every row falling back
 * to its plain DB-only status text (see DownloadsOverviewBuilder's own docblock).
 */
final class DownloadsController
{
    public function __construct(
        private readonly QbittorrentClient $client,
        private readonly DownloadsOverviewBuilder $overviewBuilder,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/downloads', name: 'downloads_index', methods: ['GET'])]
    public function index(): Response
    {
        [$torrents, $qbittorrentAvailable] = $this->fetchTorrents();
        $overview = $this->overviewBuilder->build($torrents, $qbittorrentAvailable);

        return new Response($this->twig->render('downloads/index.html.twig', [
            'rows' => $overview['rows'],
            'orphans' => $overview['orphans'],
            'qbittorrentAvailable' => $qbittorrentAvailable,
        ]));
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
