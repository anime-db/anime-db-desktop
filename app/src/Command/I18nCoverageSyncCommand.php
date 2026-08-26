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

use App\Service\I18nCoverage\AppReferenceTranslationKeysSource;
use App\Service\I18nCoverage\Github\GhCliReleaseZipDownloader;
use App\Service\I18nCoverage\Github\GhCodeownersSource;
use App\Service\I18nCoverage\Github\GhIssueExecutor;
use App\Service\I18nCoverage\Github\GhIssueStateSource;
use App\Service\I18nCoverage\Github\GhPluginReleaseTranslationKeysSource;
use App\Service\I18nCoverage\I18nCoverageSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI wrapper for issue #515: reconciles one plugin's `i18n-coverage` tracking issue in the
 * plugins monorepo against how far its latest release lags behind this app's own translation
 * keys. Wires up the real `Github\*` sources/executor and hands them, plus the app's own
 * reference key source, to {@see I18nCoverageSync} — none of the decision logic lives here.
 *
 * This is the command the (separately mounted, out of scope for issue #515 — see its own
 * docblock note below) release workflow is meant to call, one invocation per translation-type
 * plugin, once per app release.
 *
 * A plugin removed from the monorepo entirely is not handled here or anywhere else in this
 * class: its tracking issue, if any, is left open forever, since there is nothing left to
 * re-evaluate it against. Retiring that issue is manual maintainer cleanup, not something this
 * command can safely automate (it cannot tell "removed" apart from "the monorepo listing failed
 * to load" without risking a false-positive close).
 */
#[AsCommand(name: 'app:i18n-coverage:sync', description: "Reconcile a plugin's i18n-coverage tracking issue against its latest release")]
final class I18nCoverageSyncCommand extends Command
{
    private const string DEFAULT_PLUGINS_REPO = 'anime-db/anime-db-plugins';

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plugin', InputArgument::REQUIRED, 'Plugin id, matching plugins/<id>/ in the plugins monorepo')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'owner/repo of the plugins monorepo', self::DEFAULT_PLUGINS_REPO)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the computed decision without creating, editing or closing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginId = (string) $input->getArgument('plugin');
        $repo = (string) $input->getOption('repo');
        $dryRun = (bool) $input->getOption('dry-run');

        $decision = (new I18nCoverageSync())->run(
            $pluginId,
            new AppReferenceTranslationKeysSource($this->projectDir),
            new GhPluginReleaseTranslationKeysSource($pluginId, new GhCliReleaseZipDownloader($repo)),
            new GhIssueStateSource($repo, $pluginId),
            new GhCodeownersSource($repo),
            new GhIssueExecutor($repo),
            $dryRun,
        );

        $io->writeln(\sprintf('Action: %s', $decision->action->value));
        $io->writeln(\sprintf('Missing keys: %d', \count($decision->missingKeys)));
        if ($decision->missingKeys !== []) {
            $io->listing($decision->missingKeys);
        }

        if ($decision->body !== null) {
            $io->section('Body');
            $io->writeln($decision->body);
        }

        if ($decision->comment !== null) {
            $io->section('Comment');
            $io->writeln($decision->comment);
        }

        return Command::SUCCESS;
    }
}
