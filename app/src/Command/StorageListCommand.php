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

use App\Repository\StorageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot console command for the native layer (issue #1079): lists the storages that have a
 * root on disk, as `[{id, path, type}]`. The backend decides which types are listed: the
 * scannable ones ({@see \App\Entity\Storage::isScannable()} — writable types with a path), since
 * only those get a scan when they become available. Types without a path (a disc that is not
 * inserted, video media) have nothing to watch.
 */
#[AsCommand(name: 'app:storage:list', description: 'List storages that have a root on disk')]
final class StorageListCommand extends Command
{
    public function __construct(private readonly StorageRepository $storages)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as a JSON array of {id, path, type}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->storages->findAllScannable() as $storage) {
            $rows[] = [
                'id' => $storage->id,
                'path' => $storage->requirePath(),
                'type' => $storage->getType()->value,
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(\sprintf('%d  %s  %s', $row['id'], $row['type'], $row['path']));
        }

        return Command::SUCCESS;
    }
}
