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

use App\Service\Market\Exception\InvalidPluginRegistryContentException;
use App\Service\Market\Exception\PluginRegistryFetchException;
use App\Service\Market\PluginRegistry;
use App\Service\Market\PluginRegistryFetcher;
use App\Service\Market\PluginRegistrySignatureVerifier;
use App\Service\PluginContracts\LagReason;
use App\Service\PluginContracts\PluginContractPinsExtractor;
use App\Service\PluginContracts\PluginContractsCheckException;
use App\Service\PluginContracts\PluginContractsLagDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only check: does every plugin in the published registry have at least one version that
 * accepts the `anime-db/plugin-contracts` version this app tree runs on? Fetches the registry the
 * same way the market does (fetcher, signature verification, {@see PluginRegistry::fromJson()}),
 * never falls back to a cache, writes nothing and needs no credentials.
 *
 * Exit codes: 0 — no plugin lags; 1 — at least one plugin lags; 2 — the check could not be
 * performed (fetch failure, bad signature, unusable contracts version, registry data that cannot
 * be interpreted). The three outcomes must never be confused: silence is the worst result here.
 */
#[AsCommand(name: 'app:plugin-contracts:check', description: 'Check that published plugins accept the plugin-contracts version of this app')]
final class PluginContractsCheckCommand extends Command
{
    public const int EXIT_CANNOT_CHECK = 2;

    public function __construct(
        private readonly PluginRegistryFetcher $fetcher,
        private readonly PluginRegistrySignatureVerifier $signatureVerifier,
        private readonly LoggerInterface $logger,
        private readonly ?string $pluginContractsVersion,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $document = $this->fetcher->fetch();
        } catch (PluginRegistryFetchException $exception) {
            return $this->cannotCheck($io, 'the registry could not be downloaded: '.$exception->getMessage());
        }

        if (!$this->signatureVerifier->verify($document->registryJson, $document->signatureBase64)) {
            return $this->cannotCheck($io, 'the registry signature is missing, malformed, or not from a trusted key.');
        }

        try {
            $registry = PluginRegistry::fromJson($document->registryJson, $this->logger);
            $pins = (new PluginContractPinsExtractor())->extract($document->registryJson, $registry);
            $lagging = (new PluginContractsLagDetector())->detect($this->pluginContractsVersion, $pins);
        } catch (InvalidPluginRegistryContentException|PluginContractsCheckException $exception) {
            return $this->cannotCheck($io, $exception->getMessage());
        }

        $io->writeln(\sprintf('Registry source: %s (sequence %d)', $document->sourceUrl, $registry->sequence));
        $io->writeln(\sprintf('App plugin-contracts version: %s', $this->pluginContractsVersion));

        if ($lagging === []) {
            $io->success('No plugin lags behind the app plugin-contracts version.');

            return Command::SUCCESS;
        }

        foreach ($lagging as $plugin) {
            $io->writeln(match ($plugin->reason) {
                LagReason::MANIFEST_NOT_PARSEABLE => \sprintf(
                    'LAGS %s: the manifest is not parseable by this app version (latest version: %s, pin: %s)',
                    $plugin->id,
                    $plugin->latestVersion ?? 'n/a',
                    $plugin->latestPin ?? 'none',
                ),
                LagReason::NO_ACCEPTING_VERSION => \sprintf(
                    'LAGS %s: latest version %s pins plugin-contracts "%s", app has %s; no published version accepts it',
                    $plugin->id,
                    $plugin->latestVersion ?? 'n/a',
                    $plugin->latestPin ?? 'none',
                    $this->pluginContractsVersion,
                ),
            });
        }

        $io->error(\sprintf('%d plugin(s) lag behind the app plugin-contracts version.', \count($lagging)));

        return Command::FAILURE;
    }

    private function cannotCheck(SymfonyStyle $io, string $reason): int
    {
        $io->error('CANNOT CHECK: '.$reason);

        return self::EXIT_CANNOT_CHECK;
    }
}
