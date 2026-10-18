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

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\IncompatibleCoreSnapshotException;
use App\Service\Market\Exception\IncompatiblePluginContractsSnapshotException;
use App\Service\Market\Exception\MarketSnapshotUnavailableException;
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\PluginNotInSnapshotException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use App\Service\Market\InstallerPluginsSelection;
use App\Service\Market\MarketPluginInstallerInterface;
use App\Service\Plugin\Exception\IncompatiblePluginContractsVersionException;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginCacheWarmupException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginNotInstalledException;
use App\Service\Plugin\InstalledPluginsRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installs the plugins listed in the installer's selection file ({@see InstallerPluginsSelection})
 * through the same {@see MarketPluginInstallerInterface} as the market's "Install" button, but
 * without publishing the live-reload event: the native supervisor activates the whole batch
 * itself (one restart, rollback of every id on failure) from the JSON this command prints.
 *
 * Outcome per id:
 *  - already installed (before or during the run) — dropped from the file, not reported as installed;
 *  - temporary refusal (no usable snapshot, download failure, snapshot behind the registry) — the
 *    id stays in the file for the next run;
 *  - final refusal (invalid id, not in the registry, no compatible version, rejected package) —
 *    dropped from the file and logged.
 *
 * The file is rewritten after every id and removed once the list is empty. stdout is a single JSON
 * object `{"installed": [ids actually installed]}`; the exit code is non-zero only when the command
 * itself fails (e.g. the file cannot be rewritten), never because of a refusal for one id.
 */
#[AsCommand(name: 'app:plugins:install-selected', description: 'Install the plugins chosen in the installer from the market snapshot')]
final class PluginsInstallSelectedCommand extends Command
{
    public function __construct(
        private readonly InstallerPluginsSelection $selection,
        private readonly MarketPluginInstallerInterface $installer,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installed = [];

        try {
            $remaining = $this->readSelection();
            foreach ($remaining as $index => $entry) {
                $before = \count($installed);
                $processed = $this->process($entry, $installed);
                if (\count($installed) > $before) {
                    // Print the accumulated list right away: if the process is killed by a timeout, the
                    // supervisor still learns which plugins are already on disk (it takes the last line).
                    $output->writeln(json_encode(['installed' => $installed], \JSON_THROW_ON_ERROR));
                }

                if (!$processed) {
                    continue;
                }

                unset($remaining[$index]);
                $this->selection->write($remaining);
            }

            if ($remaining === []) {
                // Nothing left (or nothing to begin with, e.g. `{"plugins": []}`): the file must go.
                $this->selection->write([]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Installing the plugins chosen in the installer failed.', ['exception' => $e]);
            $output->writeln(json_encode(['installed' => $installed], \JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        $output->writeln(json_encode(['installed' => $installed], \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, mixed> entries to process, de-duplicated, keyed by position
     */
    private function readSelection(): array
    {
        try {
            $entries = $this->selection->read();
        } catch (\JsonException $e) {
            // A broken file cannot be repaired by a retry — drop it instead of failing every hour.
            $this->logger->error('The plugin selection file is malformed and was discarded.', ['exception' => $e]);
            $this->selection->write([]);

            return [];
        }

        return array_unique($entries ?? [], \SORT_REGULAR);
    }

    /**
     * @param list<string> $installed
     *
     * @return bool whether the id must be removed from the file
     */
    private function process(mixed $entry, array &$installed): bool
    {
        try {
            if (!\is_string($entry)) {
                throw new InvalidPluginIdException('The plugin selection holds a non-string entry.');
            }

            $id = new PluginId($entry);
        } catch (InvalidPluginIdException $e) {
            $this->logger->warning('Dropped an invalid plugin id from the installer selection.', ['entry' => $entry, 'exception' => $e]);

            return true;
        }

        if ($this->installedPlugins->has($id)) {
            return true;
        }

        try {
            $this->installer->installOrUpdate($id, publishWorkersReload: false);
        } catch (PluginAlreadyInstalledException) {
            return true;
        } catch (MarketSnapshotUnavailableException|PluginAssetDownloadException|UnknownPluginVersionException $e) {
            $this->logger->info('Postponed a plugin from the installer selection.', ['plugin' => (string) $id, 'reason' => $e->getMessage()]);

            return false;
        } catch (PluginNotInSnapshotException|IncompatibleCoreSnapshotException|IncompatiblePluginContractsSnapshotException|IncompatiblePluginCoreVersionException|IncompatiblePluginContractsVersionException|InvalidInstalledPluginException|PluginCacheWarmupException|PluginNotInstalledException|PluginInstallException $e) {
            $this->logger->warning('Dropped a plugin from the installer selection: it cannot be installed.', ['plugin' => (string) $id, 'exception' => $e]);

            return true;
        } catch (\Throwable $e) {
            // An unforeseen failure for one id must neither lose the ids installed before it (they are
            // printed for the supervisor to activate) nor stop the remaining ones; retried next run.
            $this->logger->error('Failed to install a plugin from the installer selection.', ['plugin' => (string) $id, 'exception' => $e]);

            return false;
        }

        $installed[] = (string) $id;

        return true;
    }
}
