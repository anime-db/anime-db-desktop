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

use App\Service\Import\StagedImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports whether the staged catalog import marker is valid, for native/supervisor/staged-import.js
 * (issue #706) — the startup decision step that must reject a staged import before FrankenPHP
 * starts, using the exact same validity rule the /settings/backup pending-import banner already
 * applies via {@see StagedImportService::readMarker()}. Spawned as a one-off `bin/console` call
 * the same way native/supervisor/migrations.js#checkDumpSchema() already asks this build's PHP a
 * yes/no question ahead of the web worker, rather than the native layer re-parsing `import.json`
 * with its own copy of the rule.
 *
 * Output is machine-readable JSON, not translated text — nothing shows it to the user directly,
 * only native/supervisor/staged-import.js parses it.
 */
#[AsCommand(name: 'app:import:staged-status', description: 'Report whether the staged catalog import marker is valid')]
final class ImportStagedStatusCommand extends Command
{
    public function __construct(
        private readonly StagedImportService $stagedImportService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $marker = $this->stagedImportService->readMarker();
        if ($marker === null) {
            return Command::FAILURE;
        }

        $output->writeln(json_encode([
            'stagedAt' => $marker->stagedAt->format(\DateTimeInterface::ATOM),
            'sourceArchive' => $marker->sourceArchive,
        ], \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
