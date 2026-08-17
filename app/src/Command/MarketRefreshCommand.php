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

use App\Service\AppConfigStore;
use App\Service\Market\MarketSnapshotBuilder;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\PluginRegistryLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The market snapshot's single writer (issue #438, part of epic #435). Everything that reads the
 * snapshot (a future storefront controller) only ever reads it — this command is the only place
 * that builds and stores a new one, so there is exactly one code path that can make the snapshot
 * disagree with the registry it was built from.
 *
 * {@see PluginRegistryLoader::load()} already does the heavy lifting (fetch, signature
 * verification, anti-rollback, raising the high-water-mark) and returns a
 * {@see \App\Service\Market\PluginRegistryLoadResult} — this command only rebuilds the snapshot
 * when that result is fresh (`isFresh()`). Anything else (servedFromCache, unavailable) means the
 * refresh itself did not succeed, so the existing snapshot is left untouched: it stays valid for
 * whoever reads it next, and the command reports failure instead.
 *
 * Self-serializes via an exclusive, non-blocking flock() on `%app.market_refresh_lock_path%`
 * (issue #438's "single writer" requirement holds even across two overlapping invocations, not
 * just within one). Losing that race is not a failure — a refresh is already in flight, so this
 * invocation simply has nothing to do and exits successfully.
 *
 * The high-water-mark is raised by load() *before* this command ever gets to run, and the snapshot
 * is written only after that — this order must not be reversed, see PluginRegistryLoader's
 * docblock: writing a snapshot ahead of the high-water-mark would let a later, still validly
 * signed but older registry pass anti-rollback and quietly roll the storefront back.
 */
#[AsCommand(name: 'app:market:refresh', description: 'Fetch, verify and rebuild the market snapshot from the plugin registry')]
final class MarketRefreshCommand extends Command
{
    private const string CONFIG_KEY_LAST_REFRESH_AT = 'marketLastRefreshAt';

    public function __construct(
        private readonly PluginRegistryLoader $registryLoader,
        private readonly MarketSnapshotBuilder $snapshotBuilder,
        private readonly MarketSnapshotCache $snapshotCache,
        private readonly AppConfigStore $configStore,
        private readonly LoggerInterface $logger,
        private readonly string $coreVersion,
        private readonly string $refreshLockPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = \dirname($this->refreshLockPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $lockHandle = fopen($this->refreshLockPath, 'c');
        if ($lockHandle === false) {
            $this->logger->error('app:market:refresh: unable to open lock file "{path}".', [
                'path' => $this->refreshLockPath,
            ]);

            return Command::FAILURE;
        }

        try {
            if (!flock($lockHandle, \LOCK_EX | \LOCK_NB)) {
                $this->logger->info('app:market:refresh: a refresh is already in progress, skipping.');

                return Command::SUCCESS;
            }

            return $this->refresh();
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function refresh(): int
    {
        $result = $this->registryLoader->load();
        $registry = $result->registry;

        if ($registry === null || $result->error !== null) {
            $this->logger->error('app:market:refresh: registry refresh failed, keeping the existing snapshot.', [
                'exception' => $result->error,
            ]);

            return Command::FAILURE;
        }

        $snapshot = $this->snapshotBuilder->build($registry, $this->coreVersion);
        $this->snapshotCache->store($snapshot);

        $this->configStore->update(static function (array $config): array {
            $config[self::CONFIG_KEY_LAST_REFRESH_AT] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return $config;
        });

        return Command::SUCCESS;
    }
}
