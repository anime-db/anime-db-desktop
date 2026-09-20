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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Empties the Messenger `messenger_messages` table on the `queue` connection (data/queue.db,
 * issue #680) — needed after a catalog import replaces `data.db`, since a queued message like
 * `PushSyncMessage` (App\Message\PushSyncMessage) addresses its target row by a bare numeric id
 * that, after the swap, may now belong to an unrelated record.
 *
 * Deliberately scoped to the `messenger_messages` table alone, not the whole `queue.db` file:
 * the same file also holds `ws_events` (see {@see \App\Service\WsPublisher}, issue #94) and
 * `job_locks` (see {@see \App\Service\JobLock\JobLockService}, issue #98), neither of which an
 * import invalidates.
 *
 * Not wired into any automatic startup path — that belongs to a separate task that decides when
 * an applied import should trigger this command. Runs from `native/` via the generic one-off
 * command launcher (`native/supervisor/php-command.js`), since `native/` carries no runtime
 * SQLite driver and cannot purge the table itself.
 */
#[AsCommand(name: 'app:queue:purge', description: 'Delete all pending Messenger messages from the queue')]
final class QueuePurgeCommand extends Command
{
    public function __construct(
        #[Target('queue.connection')]
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deletedCount = $this->connection->executeStatement('DELETE FROM messenger_messages');

        $io->success($this->translator->trans('queue_purge.success', ['%count%' => $deletedCount]));

        return Command::SUCCESS;
    }
}
