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

use App\Entity\Storage;
use App\Event\StorageConnected;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * One-shot console command for the native layer (issue #1079): reports that a storage became
 * available by dispatching the core event {@see StorageConnected}. Fails without dispatching when
 * no storage has the given id.
 */
#[AsCommand(name: 'app:storage:connected', description: 'Report that a storage has become available')]
final class StorageConnectedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Id of the storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawId = (string) $input->getArgument('id');
        $storage = ctype_digit($rawId) ? $this->entityManager->find(Storage::class, (int) $rawId) : null;
        $storageId = $storage?->id;
        if ($storageId === null) {
            $io->error(\sprintf('Storage "%s" not found.', $rawId));

            return Command::FAILURE;
        }

        $this->eventDispatcher->dispatch(new StorageConnected($storageId));

        return Command::SUCCESS;
    }
}
