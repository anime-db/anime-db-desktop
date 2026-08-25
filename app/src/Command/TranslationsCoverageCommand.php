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

use App\Entity\ValueObject\PluginId;
use App\Service\Translation\LocaleTranslationCoverage;
use App\Service\Translation\TranslationCoverageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints, per locale, how far a translation plugin's catalog covers the app's own `messages`
 * reference key set (issue #512): covered/missing/orphan key counts plus any `%name%`
 * placeholder mismatch on the keys both sides share. See
 * {@see TranslationCoverageService} for what each of those means and where the reference comes
 * from.
 *
 * `--path` is the reason this command exists rather than just a settings-page badge: it reads an
 * arbitrary directory on disk, installed or not, which is the only way a plugin author working on
 * a checkout next to this app's own can see both sides before ever packaging or installing it.
 */
#[AsCommand(name: 'app:translations:coverage', description: 'Compare a translation plugin catalog against the app reference catalog')]
final class TranslationsCoverageCommand extends Command
{
    public function __construct(private readonly TranslationCoverageService $coverageService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plugin', null, InputOption::VALUE_REQUIRED, 'Id of an installed translation plugin')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to a plugin directory on disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginId = $input->getOption('plugin');
        $path = $input->getOption('path');

        if (($pluginId === null) === ($path === null)) {
            $io->error('Provide exactly one of --plugin or --path.');

            return Command::FAILURE;
        }

        if ($path !== null) {
            $coverage = $this->coverageService->coverageForPluginDirectory((string) $path);
        } else {
            $coverage = $this->coverageService->coverageForInstalledPlugin(new PluginId((string) $pluginId));
            if ($coverage === null) {
                $io->error(\sprintf('Plugin "%s" is not installed.', $pluginId));

                return Command::FAILURE;
            }
        }

        if ($coverage === []) {
            $io->warning('No messages.<locale>.yaml catalogs found.');

            return Command::SUCCESS;
        }

        foreach ($coverage as $locale => $localeCoverage) {
            $this->renderLocale($io, $locale, $localeCoverage);
        }

        return Command::SUCCESS;
    }

    private function renderLocale(SymfonyStyle $io, string $locale, LocaleTranslationCoverage $coverage): void
    {
        $io->section(\sprintf('Locale: %s', $locale));

        if (!$coverage->isKnown) {
            $io->text('Coverage unknown: the catalog file is missing or could not be parsed.');

            return;
        }

        $io->text(\sprintf('Covered: %d', $coverage->covered));
        $io->text(\sprintf('Missing: %d', \count($coverage->missing)));
        if ($coverage->missing !== []) {
            $io->listing($coverage->missing);
        }

        $io->text(\sprintf('Orphans: %d', \count($coverage->orphans)));
        if ($coverage->orphans !== []) {
            $io->listing($coverage->orphans);
        }

        if ($coverage->placeholderMismatches !== []) {
            $io->text('Placeholder mismatches:');
            $rows = [];
            foreach ($coverage->placeholderMismatches as $key => $diff) {
                $rows[] = [$key, implode(', ', $diff['missing']), implode(', ', $diff['extra'])];
            }
            $io->table(['Key', 'Missing', 'Extra'], $rows);
        }
    }
}
