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

namespace App\Command;

use App\Repository\DownloadRepository;
use App\Service\Download\DownloadUnlinkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console front-end for {@see DownloadUnlinkService}, which also backs the anime page's "Unlink"
 * button (issue #857) — this command only resolves the (infohash, anime-id) pair to a Download row
 * and formats its {@see \App\Service\Download\DownloadUnlinkResult} as CLI output; the unlink
 * transaction itself, including the optimistic-lock race with DownloadCompletionPoller (issue
 * #837), lives in the shared service.
 */
#[AsCommand(name: 'app:downloads:unlink', description: 'Remove the pairing between a torrent infohash and an anime entry')]
final class DownloadsUnlinkCommand extends Command
{
    public function __construct(
        private readonly DownloadRepository $downloads,
        private readonly DownloadUnlinkService $unlinker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('info-hash', InputArgument::REQUIRED, 'Torrent infohash (40-char hex)')
            ->addArgument('anime-id', InputArgument::REQUIRED, 'Anime entry id')
            ->setHelp('Deletes the pairing row. If the anime\'s storage folder pointer still matches what this pairing\'s completion recorded, it is cleared too; otherwise it is left as is. Files on disk and the torrent in qBittorrent are never touched.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $infoHash = strtolower((string) $input->getArgument('info-hash'));
        $animeId = (string) $input->getArgument('anime-id');

        if (!ctype_digit($animeId)) {
            $output->writeln(\sprintf('<error>Anime id must be a non-negative integer, "%s" given.</error>', $animeId));

            return Command::FAILURE;
        }

        $download = $this->downloads->findByInfoHashAndAnime($infoHash, (int) $animeId);
        if ($download === null) {
            $output->writeln(\sprintf('<error>No pairing found for infohash %s and anime #%s.</error>', $infoHash, $animeId));

            return Command::FAILURE;
        }

        $result = $this->unlinker->unlink($download, $download->getVersion(), $download->getStatus());

        if ($result->refused) {
            $output->writeln(\sprintf('<error>Download %s / anime #%s is not completed: only a completed download can be unlinked; delete an unfinished one on the Downloads page.</error>', $infoHash, $animeId));

            return Command::FAILURE;
        }

        if (!$result->succeeded) {
            $output->writeln(\sprintf('<error>Download %s / anime #%s changed while unlinking (likely completed by the poller just now); please retry.</error>', $infoHash, $animeId));

            return Command::FAILURE;
        }

        if ($result->pointerReleased) {
            $output->writeln(\sprintf('Pairing of infohash %s with anime #%s removed; its storage folder pointer was removed from anime #%s.', $infoHash, $animeId, $animeId));
        } else {
            $output->writeln(\sprintf('Pairing of infohash %s with anime #%s removed; its storage folder pointer was kept (it does not match this download\'s snapshot, or the download completed before this feature was added).', $infoHash, $animeId));
        }

        return Command::SUCCESS;
    }
}
