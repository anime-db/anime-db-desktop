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

use App\Service\Market\MarketRefreshService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console entry point for {@see MarketRefreshService::refresh()} (issue #438, extracted into that
 * service by issue #440 so the native supervisor's startup trigger and an async messenger handler
 * can call the same refresh without going through a console process). See the service's own
 * docblock for the refresh logic itself, the single-writer flock() and the anti-rollback ordering
 * it relies on.
 */
#[AsCommand(name: 'app:market:refresh', description: 'Fetch, verify and rebuild the market snapshot from the plugin registry')]
final class MarketRefreshCommand extends Command
{
    public function __construct(private readonly MarketRefreshService $refreshService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->refreshService->refresh() ? Command::SUCCESS : Command::FAILURE;
    }
}
