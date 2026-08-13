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
 * Runs a single DownloadCompletionPoller::poll() pass (issue #346). This app has no
 * periodic-job/scheduler mechanism yet (see .claude-docs/gotchas.md), so wiring this command to
 * actually run on an interval — a supervised native-side loop, or symfony/scheduler — is left to
 * a follow-up issue, same as #345 was split from #356. Safe to invoke repeatedly (idempotent,
 * see DownloadCompletionPoller's docblock) or manually while that wiring does not exist yet.
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
