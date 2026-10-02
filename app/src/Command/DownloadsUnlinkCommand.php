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
use App\Service\Download\DownloadFolderPointer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes the pairing between a torrent (infohash) and an anime entry (issue #769). Always
 * deletes the `downloads` row. The anime's storage/storage_path pointer is cleared too, but only
 * when it still matches exactly what this pairing's completion recorded there (issue #837; see
 * DownloadFolderPointer::releaseIfOwnedBy()) — a pointer a human repointed elsewhere, or that
 * belongs to a different download, is left alone. Files on disk and the torrent in qBittorrent are
 * never touched. Once the pointer is cleared this way, the same torrent can be enqueued for
 * another anime without AnimeDownloadLinker rejecting it as a storage-path conflict.
 *
 * The delete is conditional on the row's optimistic-lock version (`DELETE ... WHERE id = ? AND
 * version = ?`): DownloadCompletionPoller runs in a separate process with no locking of its own,
 * so this command and a poll() pass can race on the same row. If the row changed (typically: the
 * poller just completed it) between this command reading it and deleting it, the delete matches
 * zero rows, the whole transaction (including any pointer it cleared) is rolled back, and the
 * command fails asking to be retried rather than silently doing nothing or acting on stale data.
 */
#[AsCommand(name: 'app:downloads:unlink', description: 'Remove the pairing between a torrent infohash and an anime entry')]
final class DownloadsUnlinkCommand extends Command
{
    public function __construct(
        private readonly DownloadRepository $downloads,
        private readonly DownloadFolderPointer $folderPointer,
        private readonly EntityManagerInterface $entityManager,
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

        $downloadId = $download->id ?? throw new \LogicException('Download loaded from the database must have an id.');
        $version = $download->getVersion();
        $released = $this->folderPointer->releaseIfOwnedBy($download);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->entityManager->flush();
        $affected = $connection->executeStatement('DELETE FROM downloads WHERE id = ? AND version = ?', [$downloadId, $version]);

        if ($affected === 0) {
            $connection->rollBack();
            $output->writeln(\sprintf('<error>Download %s / anime #%s changed while unlinking (likely completed by the poller just now); please retry.</error>', $infoHash, $animeId));

            return Command::FAILURE;
        }

        $connection->commit();

        if ($released) {
            $output->writeln(\sprintf('Pairing of infohash %s with anime #%s removed; its storage folder pointer was removed from anime #%s.', $infoHash, $animeId, $animeId));
        } else {
            $output->writeln(\sprintf('Pairing of infohash %s with anime #%s removed; its storage folder pointer was kept (it does not match this download\'s snapshot, or the download completed before this feature was added).', $infoHash, $animeId));
        }

        return Command::SUCCESS;
    }
}
