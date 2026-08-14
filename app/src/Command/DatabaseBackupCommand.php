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

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a consistent snapshot of the default connection's database to the given path, via
 * SQLite's `VACUUM INTO` rather than a plain file copy. A rollback-journal-mode database (the
 * default connection is never opened in WAL mode — see EnableWalJournalMode, which is scoped to
 * the `queue` connection only) can have a `-journal` sidecar file next to it after an unclean
 * shutdown; copying the main file alone would snapshot it mid-transaction, whereas `VACUUM INTO`
 * always produces a complete, self-contained copy (issue #392).
 */
#[AsCommand(name: 'app:database:backup', description: 'Write a consistent snapshot of the database to the given path')]
final class DatabaseBackupCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Destination file path for the backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $path */
        $path = $input->getArgument('path');

        $this->connection->executeStatement('VACUUM INTO ?', [$path]);

        $io->success(\sprintf('Database backed up to %s', $path));

        return Command::SUCCESS;
    }
}
