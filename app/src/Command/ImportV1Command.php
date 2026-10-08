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

use App\Service\Import\Exception\InvalidV1InstallationException;
use App\Service\Import\V1\V1ImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Imports the collection of an AnimeDB v1 installation into an empty catalog (issue #951). The
 * argument is the installation directory, not a database file. Messages go through `trans()`
 * (`import_v1.*`) like {@see CatalogStageCommand}, since a later onboarding screen surfaces this
 * command's outcome to the user.
 */
#[AsCommand(name: 'app:catalog:import-v1', description: 'Import the collection of an AnimeDB v1 installation into an empty catalog')]
final class ImportV1Command extends Command
{
    /** One exit code per {@see InvalidV1InstallationException::REASON_*}, read back by the caller. */
    private const int EXIT_NOT_V1_INSTALLATION = 2;
    private const int EXIT_CATALOG_NOT_EMPTY = 3;
    private const int EXIT_INVALID_RECORD = 4;

    public function __construct(
        private readonly V1ImportService $importService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Root directory of the AnimeDB v1 installation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $directory */
        $directory = $input->getArgument('directory');

        try {
            $result = $this->importService->import($directory);
        } catch (InvalidV1InstallationException $exception) {
            $io->error($this->translator->trans('import_v1.error_'.$exception->reasonKey, $exception->params));

            return match ($exception->reasonKey) {
                InvalidV1InstallationException::REASON_NOT_V1_INSTALLATION => self::EXIT_NOT_V1_INSTALLATION,
                InvalidV1InstallationException::REASON_CATALOG_NOT_EMPTY => self::EXIT_CATALOG_NOT_EMPTY,
                InvalidV1InstallationException::REASON_INVALID_RECORD => self::EXIT_INVALID_RECORD,
                default => Command::FAILURE,
            };
        }

        $io->success($result->render($this->translator));

        return Command::SUCCESS;
    }
}
