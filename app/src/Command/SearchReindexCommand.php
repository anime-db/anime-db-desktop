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

use App\Service\Search\AnimeReindexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Development/CI recovery tool for issue #198: rebuilds the Meilisearch anime index from
 * scratch, needed whenever the indexed field set changes or the index otherwise drifts from
 * the catalog. Delegates the actual traversal/indexing to AnimeReindexService so the /settings
 * "Reindex" button (SettingsController) runs the exact same logic.
 */
#[AsCommand(name: 'app:search:reindex', description: 'Rebuild the Meilisearch anime index from the catalog')]
final class SearchReindexCommand extends Command
{
    public function __construct(private readonly AnimeReindexService $reindexService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->reindexService->reindexAll();

        $io->success(\sprintf('Reindexed %d anime.', $count));

        return Command::SUCCESS;
    }
}
