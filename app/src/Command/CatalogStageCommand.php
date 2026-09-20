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

use App\Service\Import\CatalogStageService;
use App\Service\Import\Exception\InvalidCatalogArchiveException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates and unpacks a catalog export archive (issue #657) into the import staging directory
 * (issue #669) — the first of two steps a later import needs: this one only receives and unpacks
 * the archive, a separate not-yet-built command applies the staged `data.db` at the next app
 * start. Never touches the live database.
 *
 * Unlike {@see CatalogExportCommand}, whose output is plain English (nothing reads it besides a
 * developer running it by hand), this command's messages go through `trans()`: issue #669 asked
 * for translated `catalog_stage.*` keys specifically, since the /settings/backup import section
 * (issue #670, native/catalog-import/index.js) surfaces this command's outcome to the user
 * directly, the same way native/catalog-export/index.js already does for
 * {@see CatalogExportCommand}.
 */
#[AsCommand(name: 'app:catalog:stage', description: 'Validate and unpack a catalog export archive into the import staging directory')]
final class CatalogStageCommand extends Command
{
    /**
     * One exit code per {@see InvalidCatalogArchiveException::REASON_*}, same convention as
     * {@see CatalogExportCommand::EXIT_INSUFFICIENT_SPACE} — the /settings/backup import section
     * (issue #670) reads this exit code back from native/catalog-import/index.js to show a
     * specific, translated failure message instead of a generic one, without having to parse
     * this command's already-translated, locale-dependent output.
     */
    private const int EXIT_UNREADABLE = 2;
    private const int EXIT_MISSING_MANIFEST = 3;
    private const int EXIT_UNSUPPORTED_FORMAT_VERSION = 4;
    private const int EXIT_MISSING_DATABASE = 5;
    private const int EXIT_UNSAFE_ENTRY = 6;

    public function __construct(
        private readonly CatalogStageService $stageService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('archive', InputArgument::REQUIRED, 'Path to the catalog export archive to stage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $archivePath */
        $archivePath = $input->getArgument('archive');

        try {
            $result = $this->stageService->stage($archivePath);
        } catch (InvalidCatalogArchiveException $exception) {
            $io->error($this->translator->trans('catalog_stage.error_'.$exception->reasonKey, $exception->params));

            return $this->exitCodeForReason($exception->reasonKey);
        }

        $io->success($this->translator->trans('catalog_stage.success', [
            '%stagingDir%' => $result->stagingDir,
            '%animeCount%' => $result->animeCount,
            '%mediaFileCount%' => $result->mediaFileCount,
        ]));

        return Command::SUCCESS;
    }

    private function exitCodeForReason(string $reasonKey): int
    {
        return match ($reasonKey) {
            InvalidCatalogArchiveException::REASON_UNREADABLE => self::EXIT_UNREADABLE,
            InvalidCatalogArchiveException::REASON_MISSING_MANIFEST => self::EXIT_MISSING_MANIFEST,
            InvalidCatalogArchiveException::REASON_UNSUPPORTED_FORMAT_VERSION => self::EXIT_UNSUPPORTED_FORMAT_VERSION,
            InvalidCatalogArchiveException::REASON_MISSING_DATABASE => self::EXIT_MISSING_DATABASE,
            InvalidCatalogArchiveException::REASON_UNSAFE_ENTRY => self::EXIT_UNSAFE_ENTRY,
            default => Command::FAILURE,
        };
    }
}
