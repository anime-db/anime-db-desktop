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

use App\Service\Export\CatalogExportService;
use App\Service\Export\Exception\InsufficientDiskSpaceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a one-shot catalog export archive to the given directory — `data.db` (a `VACUUM INTO`
 * snapshot), `media/` and `manifest.json` (issue #657). Same "path is a CLI argument" shape as
 * {@see DatabaseBackupCommand}, so the export is reachable from the CLI directly, not only from
 * the /settings/backup page's native/catalog-export/index.js, which runs this via
 * native/supervisor/php-command.js precisely because a real catalog can take minutes and must not
 * block an HTTP request.
 *
 * Deliberately never touches `config.json`/`plugins.json`: both are plaintext behind filesystem
 * ACLs on `%AppData%` only (proxy password, plugin OAuth tokens — see ProxyConfigProvider and
 * PluginsConfigStore's own docblocks), and an exported archive leaves that ACL boundary the moment
 * it is copied anywhere else. {@see CatalogExportService} does not read either file, so there is
 * nothing here to filter out — the exclusion is structural, not a blocklist that could bit-rot.
 */
#[AsCommand(name: 'app:catalog:export', description: 'Export the catalog (database snapshot, media and manifest) as a single archive')]
final class CatalogExportCommand extends Command
{
    /**
     * Distinguishes "the destination volume does not have enough free space" from any other
     * failure (the default Command::FAILURE = 1 Symfony Console falls back to on an uncaught
     * exception) — native/catalog-export/index.js reads this exit code back to show a specific,
     * translated message on /settings/backup instead of a generic failure (issue #657 acceptance
     * criterion 6), same convention as migrations.js's STATUS_DOWNGRADE for
     * doctrine:migrations:up-to-date.
     */
    private const int EXIT_INSUFFICIENT_SPACE = 2;

    public function __construct(private readonly CatalogExportService $exportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('destination', InputArgument::REQUIRED, 'Destination directory for the export archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $destination */
        $destination = $input->getArgument('destination');

        try {
            $result = $this->exportService->export($destination);
        } catch (InsufficientDiskSpaceException $exception) {
            $io->error($exception->getMessage());

            return self::EXIT_INSUFFICIENT_SPACE;
        }

        $io->success(\sprintf(
            'Catalog exported to %s (%d anime, %d media files, %d skipped).',
            $result->archivePath,
            $result->animeCount,
            $result->mediaFileCount,
            $result->skippedMediaFiles,
        ));

        return Command::SUCCESS;
    }
}
