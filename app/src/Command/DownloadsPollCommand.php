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

use App\Service\Download\DownloadCompletionPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a single DownloadCompletionPoller::poll() pass (issue #346). Invoked from
 * native/supervisor/downloads-poll.js once at app startup (issue #685) — catching a download
 * that finished while the app was closed, since qBittorrent is a supervised sidecar that does not
 * run at all in that window. The ongoing periodic run while the app stays open goes through
 * App\MessageHandler\PollDownloadsMessageHandler on App\Scheduler\DownloadsPollSchedule's tick
 * instead of this command. Safe to invoke repeatedly, including concurrently with either of
 * those, or manually from the CLI (idempotent, see DownloadCompletionPoller's docblock).
 */
#[AsCommand(name: 'app:downloads:poll', description: 'Poll qBittorrent for finished downloads and link/emit for the ones not yet completed')]
final class DownloadsPollCommand extends Command
{
    public function __construct(private readonly DownloadCompletionPoller $poller)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->poller->poll();

        return Command::SUCCESS;
    }
}
