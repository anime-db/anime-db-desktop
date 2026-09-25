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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes the pairing between a torrent (infohash) and an anime entry (issue #769). Deletes only
 * the `downloads` row: the anime, its storage/storage_path and the torrent in qBittorrent are left
 * as they are. After that the same torrent can be enqueued for another anime.
 */
#[AsCommand(name: 'app:downloads:unlink', description: 'Remove the pairing between a torrent infohash and an anime entry')]
final class DownloadsUnlinkCommand extends Command
{
    public function __construct(private readonly DownloadRepository $downloads)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('info-hash', InputArgument::REQUIRED, 'Torrent infohash (40-char hex)')
            ->addArgument('anime-id', InputArgument::REQUIRED, 'Anime entry id')
            ->setHelp('Deletes only the pairing row. The anime, its storage_path and the torrent in qBittorrent are not touched.');
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

        $this->downloads->remove($download);
        $output->writeln(\sprintf('Pairing of infohash %s with anime #%s removed.', $infoHash, $animeId));

        return Command::SUCCESS;
    }
}
