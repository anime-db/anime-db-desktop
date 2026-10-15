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

use App\Service\Catalog\SourceUrlsRecomputeScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compares the fingerprint of the link rule set stored in `data.db` with the current one and, when
 * they differ, queues {@see \App\Message\RecomputeSourceUrlsMessage} (issue #1058).
 */
#[AsCommand(name: 'app:source-urls:check', description: 'Queue a recompute of source link comparison forms when the link rule set changed')]
final class SourceUrlsCheckCommand extends Command
{
    public function __construct(private readonly SourceUrlsRecomputeScheduler $scheduler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->scheduler->checkAndSchedule()) {
            $io->success('The link rule set changed; a recompute of source links is queued.');
        } else {
            $io->success('Source links are up to date.');
        }

        return Command::SUCCESS;
    }
}
