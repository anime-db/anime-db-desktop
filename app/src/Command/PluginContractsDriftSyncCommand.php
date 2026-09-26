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

use App\Service\I18nCoverage\CodeownersSource;
use App\Service\I18nCoverage\I18nCoverageIssueExecutor;
use App\Service\Market\Exception\InvalidPluginRegistryContentException;
use App\Service\Market\Exception\PluginRegistryFetchException;
use App\Service\Market\PluginRegistry;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistrySignatureVerifier;
use App\Service\PluginContracts\Drift\CachedCodeownersSource;
use App\Service\PluginContracts\Drift\DriftAction;
use App\Service\PluginContracts\Drift\DriftDecision;
use App\Service\PluginContracts\Drift\DriftGateway;
use App\Service\PluginContracts\Drift\PluginContractsDriftSync;
use App\Service\PluginContracts\PluginContractPins;
use App\Service\PluginContracts\PluginContractPinsExtractor;
use App\Service\PluginContracts\PluginContractsCheckException;
use App\Service\PluginContracts\PluginContractsLagDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exit codes: 0 — every plugin was reconciled; 1 — at least one plugin could not be checked (the
 * rest were processed); 2 — a registry-level failure, no issue was touched.
 */
#[AsCommand(name: 'app:plugin-contracts-drift:sync', description: "Reconcile plugins' plugin-contracts tracking issues against the plugin-contracts version of this app")]
final class PluginContractsDriftSyncCommand extends Command
{
    public const int EXIT_PLUGIN_UNCHECKED = 1;
    public const int EXIT_REGISTRY_FAILURE = 2;

    private const string DEFAULT_PLUGINS_REPO = 'anime-db/anime-db-plugins';

    public function __construct(
        private readonly PluginRegistryFetcher $fetcher,
        private readonly PluginRegistrySignatureVerifier $signatureVerifier,
        private readonly LoggerInterface $logger,
        private readonly DriftGateway $gateway,
        private readonly ?string $pluginContractsVersion,
        private readonly ?string $coreVersion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plugin', InputArgument::OPTIONAL, 'Plugin id; all plugins of the signed registry when omitted')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'owner/repo of the plugins monorepo', self::DEFAULT_PLUGINS_REPO)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read everything, but create, edit or close nothing')
            ->addOption('assume-contracts-version', null, InputOption::VALUE_REQUIRED, 'Use this plugin-contracts version instead of the one of this app (to exercise the write path by hand)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repo = (string) $input->getOption('repo');
        $dryRun = (bool) $input->getOption('dry-run');
        $only = $input->getArgument('plugin');
        $assumed = $input->getOption('assume-contracts-version');
        $contractsVersion = \is_string($assumed) && $assumed !== '' ? $assumed : $this->pluginContractsVersion;

        try {
            $document = $this->fetcher->fetch();
        } catch (PluginRegistryFetchException $exception) {
            return $this->registryFailure($io, 'the registry could not be downloaded: '.$exception->getMessage());
        }

        if (!$this->signatureVerifier->verify($document->registryJson, $document->signatureBase64)) {
            return $this->registryFailure($io, 'the registry signature is missing, malformed, or not from a trusted key.');
        }

        try {
            $registry = PluginRegistry::fromJson($document->registryJson, $this->logger);
            $extraction = (new PluginContractPinsExtractor())->extractAll($document->registryJson, $registry);
            (new PluginContractsLagDetector())->detect($contractsVersion, []);
        } catch (InvalidPluginRegistryContentException|PluginContractsCheckException $exception) {
            return $this->registryFailure($io, $exception->getMessage());
        }

        \assert($contractsVersion !== null);
        if ($this->coreVersion === null || $this->coreVersion === '') {
            return $this->registryFailure($io, 'the version of this app is unknown.');
        }

        $byId = [];
        foreach ($extraction->plugins as $pins) {
            $byId[$pins->id] ??= $pins;
        }

        if (\is_string($only) && $only !== '') {
            if (!isset($byId[$only])) {
                return $this->registryFailure($io, \sprintf('plugin "%s" is not in the registry.', $only));
            }
            $byId = [$only => $byId[$only]];
        }

        try {
            $labelPresent = $this->gateway->stateSource($repo, '-', PluginContractsDriftSync::TRACKING_LABEL)->hasLabel(PluginContractsDriftSync::TRACKING_LABEL);
        } catch (\Throwable $exception) {
            return $this->registryFailure($io, 'the label check failed: '.$exception->getMessage());
        }

        if (!$labelPresent) {
            if (!$dryRun) {
                return $this->registryFailure($io, \sprintf('the label "%s" does not exist in %s; create it first.', PluginContractsDriftSync::TRACKING_LABEL, $repo));
            }
            $io->warning(\sprintf('The label "%s" does not exist in %s; a real run would fail here.', PluginContractsDriftSync::TRACKING_LABEL, $repo));
        }

        $io->writeln(\sprintf('App plugin-contracts version: %s%s', $contractsVersion, $contractsVersion === $this->pluginContractsVersion ? '' : ' (assumed)'));

        $failed = $this->syncAll($io, $byId, $repo, $contractsVersion, $dryRun);

        if ($extraction->entriesWithoutId > 0 && !\is_string($only)) {
            $io->writeln(\sprintf('CANNOT CHECK: %d registry entrie(s) without a string "id".', $extraction->entriesWithoutId));
            $failed = true;
        }

        return $failed ? self::EXIT_PLUGIN_UNCHECKED : Command::SUCCESS;
    }

    /**
     * @param array<string, PluginContractPins> $byId
     *
     * @return bool whether at least one plugin could not be checked
     */
    private function syncAll(SymfonyStyle $io, array $byId, string $repo, string $contractsVersion, bool $dryRun): bool
    {
        $sync = new PluginContractsDriftSync();
        $executor = $this->gateway->executor($repo);
        $codeowners = new CachedCodeownersSource($this->gateway->codeowners($repo));
        \assert($this->coreVersion !== null);

        $failed = false;
        foreach ($byId as $id => $pins) {
            try {
                $decision = $this->syncPlugin($sync, $pins, $contractsVersion, $repo, $codeowners, $executor, $dryRun);
            } catch (\Throwable $exception) {
                $io->writeln(\sprintf('CANNOT CHECK %s: %s', $id, $exception->getMessage()));
                $failed = true;

                continue;
            }

            if ($decision->action === DriftAction::CANNOT_CHECK) {
                $io->writeln(\sprintf('CANNOT CHECK %s: %s', $id, $decision->problem));
                $failed = true;

                continue;
            }

            $io->writeln(\sprintf('%s: %s%s', $id, $decision->action->value, $dryRun ? ' (dry run)' : ''));
            if ($dryRun && $decision->body !== null) {
                $io->section('Body');
                $io->writeln($decision->body);
            }
            if ($dryRun && $decision->comment !== null) {
                $io->section('Comment');
                $io->writeln($decision->comment);
            }
        }

        return $failed;
    }

    private function syncPlugin(
        PluginContractsDriftSync $sync,
        PluginContractPins $pins,
        string $contractsVersion,
        string $repo,
        CodeownersSource $codeowners,
        I18nCoverageIssueExecutor $executor,
        bool $dryRun,
    ): DriftDecision {
        \assert($this->coreVersion !== null);

        return $sync->run(
            $pins,
            $contractsVersion,
            $this->coreVersion,
            $this->gateway->stateSource($repo, $pins->id, PluginContractsDriftSync::TRACKING_LABEL),
            $codeowners,
            $executor,
            $dryRun,
        );
    }

    private function registryFailure(SymfonyStyle $io, string $reason): int
    {
        $io->error('CANNOT SYNC: '.$reason.' No issue was touched.');

        return self::EXIT_REGISTRY_FAILURE;
    }
}
