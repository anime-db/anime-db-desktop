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

namespace App\Service\Market;

use App\Service\AppConfigStore;
use Psr\Log\LoggerInterface;

/**
 * The market snapshot's single writer (issue #438, part of epic #435), extracted out of
 * {@see \App\Command\MarketRefreshCommand} (issue #440) so both that command and an async
 * messenger handler ({@see \App\MessageHandler\RefreshMarketSnapshotMessageHandler}) can trigger
 * the same refresh without duplicating it. Everything that reads the snapshot (e.g.
 * {@see \App\Controller\Settings\MarketController}) only ever reads it — this service is the only
 * place that builds and stores a new one, so there is exactly one code path that can make the
 * snapshot disagree with the registry it was built from.
 *
 * {@see PluginRegistryLoader::load()} already does the heavy lifting (fetch, signature
 * verification, anti-rollback, raising the high-water-mark) and returns a
 * {@see PluginRegistryLoadResult} — this service only rebuilds the snapshot when that result is
 * fresh (`isFresh()`). Anything else (servedFromCache, unavailable) means the refresh itself did
 * not succeed, so the existing snapshot is left untouched: it stays valid for whoever reads it
 * next, and this method reports failure instead.
 *
 * Self-serializes via an exclusive, non-blocking flock() on `%app.market_refresh_lock_path%` —
 * the "single writer" requirement holds across every caller (the command and the messenger
 * handler alike), not just within one of them. Losing that race is not a failure — a refresh is
 * already in flight, so this invocation simply has nothing to do and reports success.
 *
 * The high-water-mark is raised by load() *before* this method ever gets to run, and the snapshot
 * is written only after that — this order must not be reversed, see PluginRegistryLoader's
 * docblock: writing a snapshot ahead of the high-water-mark would let a later, still validly
 * signed but older registry pass anti-rollback and quietly roll the storefront back.
 */
final class MarketRefreshService
{
    private const string CONFIG_KEY_LAST_REFRESH_AT = 'marketLastRefreshAt';

    /**
     * Recorded unconditionally at the start of every attempt (issue #446 review), unlike
     * {@see self::CONFIG_KEY_LAST_REFRESH_AT} above which only moves on success. Callers that want
     * to throttle how often they *trigger* a refresh (e.g. {@see \App\Controller\Settings\MarketController})
     * need to know when the last attempt started, not when one last completed — otherwise a
     * persistently failing registry fetch (offline, unreachable mirror, ...) never gets a recorded
     * timestamp to throttle against, and every caller keeps re-dispatching.
     */
    public const string CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT = 'marketLastRefreshAttemptAt';

    public function __construct(
        private readonly PluginRegistryLoader $registryLoader,
        private readonly MarketSnapshotBuilder $snapshotBuilder,
        private readonly MarketSnapshotCache $snapshotCache,
        private readonly AppConfigStore $configStore,
        private readonly LoggerInterface $logger,
        private readonly string $coreVersion,
        private readonly string $refreshLockPath,
    ) {
    }

    public function refresh(): bool
    {
        $directory = \dirname($this->refreshLockPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $lockHandle = fopen($this->refreshLockPath, 'c');
        if ($lockHandle === false) {
            $this->logger->error('market refresh: unable to open lock file "{path}".', [
                'path' => $this->refreshLockPath,
            ]);

            return false;
        }

        try {
            if (!flock($lockHandle, \LOCK_EX | \LOCK_NB)) {
                $this->logger->info('market refresh: a refresh is already in progress, skipping.');

                return true;
            }

            return $this->doRefresh();
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function doRefresh(): bool
    {
        $this->configStore->update(static function (array $config): array {
            $config[self::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return $config;
        });

        $result = $this->registryLoader->load();
        $registry = $result->registry;

        if ($registry === null || $result->error !== null) {
            $this->logger->error('market refresh: registry refresh failed, keeping the existing snapshot.', [
                'exception' => $result->error,
            ]);

            return false;
        }

        $snapshot = $this->snapshotBuilder->build($registry, $this->coreVersion);
        $this->snapshotCache->store($snapshot);

        $this->configStore->update(static function (array $config): array {
            $config[self::CONFIG_KEY_LAST_REFRESH_AT] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return $config;
        });

        return true;
    }
}
