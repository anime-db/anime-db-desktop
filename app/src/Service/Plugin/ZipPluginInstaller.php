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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Manifest\InvalidManifestException;
use AnimeDb\PluginContracts\Manifest\InvalidManifestJsonException;
use AnimeDb\PluginContracts\Manifest\Manifest;
use AnimeDb\PluginContracts\Manifest\ManifestParser;
use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\IncompatiblePluginContractsVersionException;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginNotInstalledException;
use App\Service\Plugin\Exception\PluginSyntaxErrorException;
use App\Service\WsPublisher;
use Composer\Semver\Semver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Installs a plugin from an uploaded ZIP archive: unpacks it into a private staging directory
 * first, validates its `manifest.json` there, and only then moves it into
 * `%app.plugins_dir%/<pluginId>/` and re-runs {@see InstalledPluginsRegistry::reconcile()} to
 * pick it up. Any failure along the way — a corrupt archive, a missing/invalid manifest, an id
 * collision, or a filesystem error moving the unpacked directory into place — rolls back
 * whatever was created, so a failed install never leaves a partial plugin behind.
 *
 * The staging directory lives at `dirname(%app.plugins_dir%)/.plugin-install-tmp/<random>`,
 * i.e. a sibling of `%app.plugins_dir%` rather than the system temp directory
 * ({@see self::stagingRootDir()}). Two constraints drive that: it must be outside
 * `%app.plugins_dir%` itself so {@see InstalledPluginsRegistry::reconcile()} never scans a
 * half-unpacked directory as a plugin candidate, and it must be on the *same filesystem* as
 * `%app.plugins_dir%` so the final `rename()` into place is a same-volume move — `rename()`
 * fails with EXDEV across filesystem boundaries (e.g. a tmpfs `/tmp` next to a persistent
 * `%app.plugins_dir%` on Linux, or `%TEMP%` and `%AppData%` on different drives on Windows),
 * which the system temp directory does not guarantee.
 *
 * Also runs a blocking compatibility check (issue #249) right after the manifest is parsed:
 * the manifest's `require.core` lower-bound constraint (e.g. `">=2.0.0"`) is checked against
 * the current `%app.core_version%` via {@see Semver::satisfies()}, before anything is moved
 * into place — see {@see IncompatiblePluginCoreVersionException}.
 *
 * Right next to it runs a second, independent check (issue #561) against the optional
 * `require.plugin-contracts` constraint, if the manifest declares one: the app version alone
 * cannot express a breaking change in the `anime-db/plugin-contracts` interfaces/DTOs a plugin
 * actually compiles against, since every published plugin's `require.core` is a permissive
 * `">=0.0.1"`. Compared against `%app.plugin_contracts_version%` — the version this app itself
 * vendors, read via `Composer\InstalledVersions::getPrettyVersion()` (see {@see \App\Kernel}) —
 * not the `^0.15`-style constraint from this app's own `composer.json`. Fails open (skips the
 * check, logs) when that version cannot be determined or the manifest's constraint does not
 * parse, and is a no-op when the manifest omits the field entirely — see
 * {@see self::assertPluginContractsCompatible()} and {@see IncompatiblePluginContractsVersionException}.
 *
 * Also checks every unpacked `*.php` file for PHP syntax errors (issue #250), still before
 * anything is moved into place — see {@see self::assertNoSyntaxErrors()} — unless {@see install()}
 * is called with `$trusted = true`. That is the marketplace install path (issue #220): those
 * plugins were already linted on the registry side by CI before ever reaching
 * `plugins-registry.json`, so repeating the check client-side would be redundant, not defense in
 * depth.
 *
 * After the plugin is moved into place and the registry re-synced, runs {@see PluginCacheWarmer}
 * (issue #222) to compile the DI container with the new plugin present, in an isolated process —
 * this is what actually gets to decide whether the install as a whole succeeds, on top of
 * everything checked above.
 *
 * Once that isolated warm-up succeeds, publishes {@see self::WORKERS_RELOAD_EVENT} over
 * {@see WsPublisher} (issue #411): the isolated warm-up only proves the container *compiles* with
 * the new plugin, it does not make the plugin live — a FrankenPHP worker that is already running
 * keeps its previously compiled container in memory regardless. `native/supervisor/index.js`
 * subscribes to this event over the existing `/ws` channel and does the actual activation:
 * invalidate the real compiled-container cache and restart the live FrankenPHP worker and
 * messenger-consumer processes, rolling back (via `app:plugin:deactivate`) if the restarted
 * worker fails its healthcheck. This publish happens after the install is otherwise complete and
 * is best-effort: a failure to enqueue the event only logs a warning rather than rolling back the
 * install, since "installed but not yet live" is itself a recoverable, expected state (the plugin
 * activates on the next full app restart regardless of whether this notification got through).
 *
 * {@see self::update()} (issue #224) is the counterpart for a plugin id that *is* already
 * installed: it runs the exact same validation chain as {@see self::install()} above — manifest
 * parsing, core-version compatibility, syntax lint unless `$trusted` — before touching anything on
 * disk, then swaps the new version into place behind a backup of the old one instead of
 * {@see self::install()}'s plain move, so a failed isolated warm-up can restore the previous,
 * still-working version rather than leaving the plugin directory empty. `plugins.json`
 * ({@see PluginsConfigStore}) is never touched by either method: a plugin's settings live there,
 * not in its directory, so they survive the directory swap unconditionally — migrating a settings
 * schema across versions is the plugin's own init code's job, not this installer's.
 *
 * Both {@see self::install()} and {@see self::update()} run their mutating work under
 * {@see InstalledPluginsRegistry::synchronized()} (issue #420), the same exclusive lock
 * {@see InstalledPluginsRegistry::reconcile()} itself acquires: FrankenPHP's worker mode runs
 * requests in parallel on a shared filesystem, so without it a concurrent install of the same
 * plugin id (or an install racing a remove) could interleave — see the lock's own docblock.
 *
 * Deliberately still out of scope here: any UI, and enabling an already-installed plugin.
 */
final class ZipPluginInstaller
{
    private const STAGING_DIR_NAME = '.plugin-install-tmp';

    /** @see self::moveWithRetries() */
    private const int RESTORE_MOVE_MAX_ATTEMPTS = 5;
    private const int RESTORE_MOVE_RETRY_DELAY_MICROSECONDS = 200_000;

    /**
     * Keep this string in sync with WORKERS_RELOAD_EVENT in native/supervisor/index.js — a
     * mismatch breaks live plugin activation silently, the same lesson as issue #336/#361 for
     * PROXY_CHANGED_EVENT/FIREWALL_RULE_CHANGED_EVENT.
     */
    public const string WORKERS_RELOAD_EVENT = 'workers.reload';

    public function __construct(
        private readonly string $pluginsDir,
        /**
         * The app's own core version (issue #565), or `null` when it could not be determined —
         * see {@see self::assertCoreVersionCompatible()} and the class docblock's plugin-contracts
         * axis, which fails open the same way.
         */
        private readonly ?string $coreVersion,
        private readonly InstalledPluginsRegistry $registry,
        private readonly PluginCacheWarmerInterface $cacheWarmer,
        private readonly WsPublisher $wsPublisher,
        /**
         * The installed `anime-db/plugin-contracts` version (issue #561), or `null` when it could
         * not be determined — see the class docblock. Defaults to `null` rather than requiring
         * every caller to pass it, so the plugin-contracts check itself fails open the same way it
         * would for a real "unknown version" at runtime.
         */
        private readonly ?string $pluginContractsVersion = null,
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param bool $trusted skips the PHP syntax check (issue #220's marketplace path — see
     *                      the class docblock); the custom-ZIP-upload path (issue #251) leaves
     *                      this at its default `false`
     *
     * @throws InvalidInstalledPluginException             if manifest.json is missing or invalid
     * @throws IncompatiblePluginCoreVersionException      if the current core version does not satisfy
     *                                                     the manifest's `require.core` lower bound
     * @throws IncompatiblePluginContractsVersionException if the manifest declares a
     *                                                     `require.plugin-contracts` constraint the
     *                                                     installed plugin-contracts version does
     *                                                     not satisfy
     * @throws PluginSyntaxErrorException                  if any `*.php` file in the archive has a PHP syntax error
     *                                                     (never thrown when `$trusted` is `true`)
     * @throws PluginAlreadyInstalledException             if the manifest's plugin id is already installed
     * @throws PluginInstallException                      if the archive cannot be unpacked or moved into place
     * @throws Exception\PluginCacheWarmupException        if the isolated cache warm-up fails to
     *                                                     compile the DI container with the new
     *                                                     plugin present
     */
    public function install(string $zipPath, bool $trusted = false): PluginId
    {
        $pluginId = $this->registry->synchronized(fn (): PluginId => $this->doInstall($zipPath, $trusted));

        // Deliberately outside the lock and the try/catch inside doInstall(): the install is
        // already complete at this point (moved into place, registry re-synced, cache warm-up
        // passed), so a failure to publish this best-effort notification must not roll it back —
        // see the class docblock. There is also no reason to keep holding the exclusive plugin
        // filesystem lock for a notification that touches neither the index nor a plugin directory.
        try {
            $this->wsPublisher->publish(self::WORKERS_RELOAD_EVENT, ['pluginId' => (string) $pluginId]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to publish {event} for plugin {pluginId}: {message}', [
                'event' => self::WORKERS_RELOAD_EVENT,
                'pluginId' => (string) $pluginId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        return $pluginId;
    }

    /**
     * The mutating body of {@see self::install()}, run under {@see InstalledPluginsRegistry::synchronized()}
     * so a concurrent install of the same plugin id blocks instead of racing this one — the
     * loser sees {@see PluginAlreadyInstalledException} from the checks below once the winner's
     * whole operation has already finished, rather than both passing the check and one deleting
     * the other's freshly installed directory (issue #420).
     */
    private function doInstall(string $zipPath, bool $trusted): PluginId
    {
        $tmpDir = $this->createTmpDir();
        $moveStarted = false;
        $targetDir = null;

        try {
            $this->extract($zipPath, $tmpDir);
            $pluginRoot = $this->resolvePluginRoot($tmpDir);
            $manifest = $this->parseManifest($pluginRoot);
            $this->assertCoreVersionCompatible($manifest);
            $this->assertPluginContractsCompatible($manifest);
            $this->assertNativeTranslationsAreReadable($manifest, $pluginRoot);
            if (!$trusted) {
                $this->assertNoSyntaxErrors($pluginRoot);
            }
            $pluginId = new PluginId($manifest->id);
            $targetDir = $this->pluginsDir.\DIRECTORY_SEPARATOR.$pluginId;

            if ($this->registry->has($pluginId) || is_dir($targetDir)) {
                throw new PluginAlreadyInstalledException($pluginId);
            }

            $moveStarted = true;
            $this->move($pluginRoot, $targetDir);

            $this->registry->reconcile();

            $this->cacheWarmer->warmUp();
        } catch (\Throwable $exception) {
            if ($moveStarted && $targetDir !== null) {
                $this->rollbackFailedMove($targetDir);
            }

            throw $exception;
        } finally {
            $this->cleanupStagingDirectory($tmpDir);
        }

        return $pluginId;
    }

    /**
     * Rolls back a directory moved into place by a failed {@see self::doInstall()}. Deliberately
     * swallows (logs instead of throwing) any failure of its own: this already runs inside a catch
     * block reacting to the real failure (a bad manifest, an incompatible core version, a failed
     * cache warm-up, ...), and letting a rollback failure replace that exception would mask the
     * actual reason the install failed behind an unrelated filesystem error.
     */
    private function rollbackFailedMove(string $targetDir): void
    {
        try {
            PluginDirectoryRemover::remove($targetDir);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to remove a plugin directory while rolling back a failed install.', [
                'targetDir' => $targetDir,
                'exception' => $exception,
            ]);
        }

        // The index may already have been rewritten with an entry pointing at the directory just
        // (attempted to be) removed above (e.g. a cache warm-up failure, which runs after
        // reconcile()) — re-sync it so a stale entry does not outlive the rollback.
        try {
            $this->registry->reconcile();
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to re-sync the plugin index after rolling back a failed install.', [
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Updates an already-installed plugin to the version packaged in the given ZIP (issue #224):
     * runs the same validation chain as {@see self::install()} (manifest parsing, core-version
     * compatibility, syntax lint unless `$trusted`), then swaps the new version into place behind
     * a backup of the current one rather than moving it directly on top — so a failed isolated
     * warm-up can restore the previous version instead of leaving the plugin directory empty or
     * half-written. The backup lives beside the extraction staging directory
     * ({@see self::stagingRootDir()}), i.e. outside `%app.plugins_dir%`, so
     * {@see InstalledPluginsRegistry::reconcile()} never scans it as a second copy of the same
     * plugin id while both directories briefly coexist.
     *
     * The live FrankenPHP worker is never touched while a warm-up is in flight (activation only
     * happens via {@see self::WORKERS_RELOAD_EVENT} after a successful one, same as
     * {@see self::install()}), so a failed warm-up leaves it running the old version the whole
     * time — restoring the backup here is enough to make the on-disk state consistent again, no
     * separate worker-side rollback is needed.
     *
     * @param bool $trusted skips the PHP syntax check — see {@see self::install()}'s parameter
     *                      of the same name for when to set it
     *
     * @throws InvalidInstalledPluginException             if manifest.json is missing or invalid
     * @throws IncompatiblePluginCoreVersionException      if the current core version does not satisfy
     *                                                     the manifest's `require.core` lower bound
     * @throws IncompatiblePluginContractsVersionException if the manifest declares a
     *                                                     `require.plugin-contracts` constraint the
     *                                                     installed plugin-contracts version does
     *                                                     not satisfy
     * @throws PluginSyntaxErrorException                  if any `*.php` file in the archive has a PHP syntax error
     *                                                     (never thrown when `$trusted` is `true`)
     * @throws PluginNotInstalledException                 if the manifest's plugin id has no existing
     *                                                     installation to update
     * @throws PluginInstallException                      if the archive cannot be unpacked, or a directory
     *                                                     cannot be moved into place
     * @throws Exception\PluginCacheWarmupException        if the isolated cache warm-up fails to
     *                                                     compile the DI container with the updated
     *                                                     plugin present — the previous version is
     *                                                     restored before this propagates
     */
    public function update(string $zipPath, bool $trusted = false): PluginId
    {
        $pluginId = $this->registry->synchronized(fn (): PluginId => $this->doUpdate($zipPath, $trusted));

        // Deliberately outside the lock and the try/catch inside doUpdate(): the update is
        // already complete at this point (new version moved into place, registry re-synced,
        // cache warm-up passed), so a failure to publish this best-effort notification must not
        // roll it back — see the class docblock.
        try {
            $this->wsPublisher->publish(self::WORKERS_RELOAD_EVENT, ['pluginId' => (string) $pluginId]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to publish {event} for plugin {pluginId}: {message}', [
                'event' => self::WORKERS_RELOAD_EVENT,
                'pluginId' => (string) $pluginId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        return $pluginId;
    }

    /**
     * The mutating body of {@see self::update()}, run under {@see InstalledPluginsRegistry::synchronized()}
     * — same rationale as {@see self::doInstall()}.
     */
    private function doUpdate(string $zipPath, bool $trusted): PluginId
    {
        $tmpDir = $this->createTmpDir();
        $targetDir = null;
        $backupDir = null;
        $backedUp = false;
        $newVersionInPlace = false;

        try {
            $this->extract($zipPath, $tmpDir);
            $pluginRoot = $this->resolvePluginRoot($tmpDir);
            $manifest = $this->parseManifest($pluginRoot);
            $this->assertCoreVersionCompatible($manifest);
            $this->assertPluginContractsCompatible($manifest);
            $this->assertNativeTranslationsAreReadable($manifest, $pluginRoot);
            if (!$trusted) {
                $this->assertNoSyntaxErrors($pluginRoot);
            }
            $pluginId = new PluginId($manifest->id);
            $targetDir = $this->pluginsDir.\DIRECTORY_SEPARATOR.$pluginId;

            if (!$this->registry->has($pluginId) && !is_dir($targetDir)) {
                throw new PluginNotInstalledException($pluginId);
            }

            $backupDir = $this->stagingRootDir().\DIRECTORY_SEPARATOR.'anime-db-plugin-update-backup-'.bin2hex(random_bytes(8));
            $this->move($targetDir, $backupDir);
            $backedUp = true;

            $this->move($pluginRoot, $targetDir);
            $newVersionInPlace = true;

            $this->registry->reconcile();

            $this->cacheWarmer->warmUp();
        } catch (\Throwable $exception) {
            if ($backedUp && $targetDir !== null && $backupDir !== null) {
                $this->restoreBackup($targetDir, $backupDir, $newVersionInPlace);
            }

            throw $exception;
        } finally {
            $this->cleanupStagingDirectory($tmpDir);
        }

        $this->cleanupStagingDirectory($backupDir);

        return $pluginId;
    }

    /**
     * Restores the previous version after a failed update. Retries the swap back — a file inside
     * either directory may briefly still be held open, the same reason {@see PluginCacheWarmer::removeDirectory()}
     * retries its own cleanup — and deliberately never lets a restore failure itself replace the
     * exception that triggered the rollback: that would mask e.g. the actual
     * {@see Exception\PluginCacheWarmupException} behind an unrelated filesystem error, exactly
     * the failure mode issue #420 calls out. A restore failure is logged instead, and the index is
     * best-effort re-synced regardless: with $targetDir left missing, empty, or only partially
     * restored, {@see InstalledPluginsRegistry::reconcile()} simply finds no readable
     * `manifest.json` there and drops the plugin from the index rather than leaving it pointing at
     * a directory that no longer holds a working plugin.
     */
    private function restoreBackup(string $targetDir, string $backupDir, bool $newVersionInPlace): void
    {
        try {
            if ($newVersionInPlace) {
                PluginDirectoryRemover::remove($targetDir);
            }

            $this->moveWithRetries($backupDir, $targetDir);
            $this->registry->reconcile();
        } catch (\Throwable $restoreException) {
            $this->logger->error('Failed to restore the previous plugin version after a failed update.', [
                'targetDir' => $targetDir,
                'backupDir' => $backupDir,
                'exception' => $restoreException,
            ]);

            try {
                $this->registry->reconcile();
            } catch (\Throwable $reconcileException) {
                $this->logger->error('Failed to re-sync the plugin index after a failed update restore.', [
                    'exception' => $reconcileException,
                ]);
            }
        }
    }

    private function stagingRootDir(): string
    {
        return \dirname($this->pluginsDir).\DIRECTORY_SEPARATOR.self::STAGING_DIR_NAME;
    }

    private function createTmpDir(): string
    {
        $tmpDir = $this->stagingRootDir().\DIRECTORY_SEPARATOR.'anime-db-plugin-install-'.bin2hex(random_bytes(8));

        if (!mkdir($tmpDir, recursive: true) && !is_dir($tmpDir)) {
            throw new PluginInstallException(\sprintf('Unable to create temporary directory "%s".', $tmpDir));
        }

        return $tmpDir;
    }

    private function extract(string $zipPath, string $targetDir): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new PluginInstallException(\sprintf('Unable to open ZIP archive "%s" (error code %s).', $zipPath, $openResult));
        }

        try {
            $this->assertSafeEntryNames($zip, $zipPath);

            if (!$zip->extractTo($targetDir)) {
                throw new PluginInstallException(\sprintf('Unable to extract ZIP archive "%s".', $zipPath));
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Defence in depth against zip-slip: an untrusted archive (this is a custom-upload path, not
     * only the CI-packaged marketplace flow from issue #220) could contain entry names with `..`
     * segments or absolute paths designed to write outside the staging directory. Modern
     * {@see \ZipArchive::extractTo()} already rejects those, but that behaviour is not part of
     * its documented contract, so entry names are validated explicitly before extraction rather
     * than relying on it.
     *
     * @throws PluginInstallException
     */
    private function assertSafeEntryNames(\ZipArchive $zip, string $zipPath): void
    {
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $isAbsolute = str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('#^[A-Za-z]:#', $name) === 1;
            $hasParentTraversal = \in_array('..', explode('/', str_replace('\\', '/', $name)), true);

            if ($isAbsolute || $hasParentTraversal) {
                throw new PluginInstallException(\sprintf('ZIP archive "%s" contains an unsafe entry path "%s".', $zipPath, $name));
            }
        }
    }

    /**
     * Locates the directory that should actually contain `manifest.json`. A ZIP created by
     * packaging a directory directly (Windows Explorer, `zip -r plugin.zip plugin/`) commonly
     * wraps everything in one top-level directory instead of putting `manifest.json` at the
     * archive root; this descends into it transparently. Anything else (no top-level wrapper, or
     * more than one top-level entry) is left as-is and surfaces as a missing-manifest error from
     * {@see self::parseManifest()}, since there is no unambiguous root to pick.
     */
    private function resolvePluginRoot(string $tmpDir): string
    {
        if (is_file($tmpDir.\DIRECTORY_SEPARATOR.'manifest.json')) {
            return $tmpDir;
        }

        $entries = array_values(array_diff((array) scandir($tmpDir), ['.', '..']));
        if (\count($entries) === 1) {
            $nested = $tmpDir.\DIRECTORY_SEPARATOR.$entries[0];
            if (is_dir($nested) && is_file($nested.\DIRECTORY_SEPARATOR.'manifest.json')) {
                return $nested;
            }
        }

        return $tmpDir;
    }

    /**
     * @throws InvalidInstalledPluginException
     */
    private function parseManifest(string $dir): Manifest
    {
        $manifestPath = $dir.\DIRECTORY_SEPARATOR.'manifest.json';
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if ($contents === false) {
            throw new InvalidInstalledPluginException($dir, []);
        }

        try {
            return $this->manifestParser->parse($contents);
        } catch (InvalidManifestException $exception) {
            throw new InvalidInstalledPluginException($dir, $exception->errors, $exception);
        } catch (InvalidManifestJsonException $exception) {
            throw new InvalidInstalledPluginException($dir, [], $exception);
        }
    }

    /**
     * A `null` {@see self::$coreVersion} (this app build could not determine it) fails open here,
     * same shape as {@see self::assertPluginContractsCompatible()}'s own `null` branch below:
     * nothing gets blocked on an axis this app cannot itself evaluate.
     *
     * @throws IncompatiblePluginCoreVersionException
     */
    private function assertCoreVersionCompatible(Manifest $manifest): void
    {
        if ($this->coreVersion === null) {
            $this->logger->warning('Unable to determine the installed core version; skipping the core compatibility check.', [
                'pluginId' => $manifest->id,
                'requiredCore' => $manifest->require->core,
            ]);

            return;
        }

        if (!Semver::satisfies($this->coreVersion, $manifest->require->core)) {
            throw new IncompatiblePluginCoreVersionException($manifest->require->core, $this->coreVersion);
        }
    }

    /**
     * A manifest without `require.plugin-contracts` declares no constraint on this axis at all —
     * true for every `translation`-type plugin and part of the historical `integration` catalog —
     * so that case passes here without even reaching {@see Semver::satisfies()}, same fail-open
     * shape as the two branches below it. See the class docblock for why this check exists
     * alongside {@see self::assertCoreVersionCompatible()} rather than folding into it.
     *
     * @throws IncompatiblePluginContractsVersionException
     */
    private function assertPluginContractsCompatible(Manifest $manifest): void
    {
        $required = $manifest->require->pluginContracts;
        if ($required === null) {
            return;
        }

        if ($this->pluginContractsVersion === null) {
            $this->logger->warning('Unable to determine the installed plugin-contracts version; skipping the plugin-contracts compatibility check.', [
                'pluginId' => $manifest->id,
                'requiredPluginContracts' => $required,
            ]);

            return;
        }

        try {
            $satisfies = Semver::satisfies($this->pluginContractsVersion, $required);
        } catch (\UnexpectedValueException $exception) {
            $this->logger->warning('Unable to parse the manifest\'s "require.plugin-contracts" constraint; skipping the plugin-contracts compatibility check.', [
                'pluginId' => $manifest->id,
                'requiredPluginContracts' => $required,
                'exception' => $exception,
            ]);

            return;
        }

        if (!$satisfies) {
            throw new IncompatiblePluginContractsVersionException($required, $this->pluginContractsVersion);
        }
    }

    /**
     * Validates the unpacked archive's `translations/native/` directory, if any, before anything
     * is moved into place (issue #647) — the same "fail before committing" philosophy as
     * {@see self::assertNoSyntaxErrors()} just below. This is the one piece of validation
     * `App\Service\Translation\NativeTranslationsOverlayWriter` itself deliberately does not
     * perform for the plugin currently being installed or updated: that class treats a broken
     * `translations/native/` directory as a bystander's problem — log it and move on, never fail
     * the unrelated install/update/remove that happened to trigger its rebuild (see its own class
     * docblock) — precisely because this method already guarantees the directory is readable and
     * well-formed by the time the new version ever reaches
     * {@see InstalledPluginsRegistry::reconcile()}.
     *
     * Only {@see PluginType::Translation} plugins are checked — the only type the overlay writer
     * ever reads a `translations/native/` directory from at all. A missing directory is not an
     * error: most translation plugins ship none, the native layer's own catalog (splash/tray/
     * dialogs) is a small surface most language packs have no reason to cover.
     *
     * Deliberately checks only that each `*.json` file is readable and decodes to a JSON object —
     * not the sanitization rules (reference key set, string-only values, the 1000-character cap)
     * {@see \App\Service\Translation\NativeTranslationsOverlayWriter} applies afterwards. Those
     * are content-filtering rules with a well-defined "drop and log" outcome for a single key, not
     * "this plugin cannot be installed at all" — nothing here should reject an otherwise-valid
     * plugin ZIP just because one translation value is too long.
     *
     * @throws PluginInstallException if the directory exists but cannot be scanned, or any
     *                                `*.json` file inside it cannot be read or does not decode to
     *                                a JSON object
     */
    private function assertNativeTranslationsAreReadable(Manifest $manifest, string $pluginRoot): void
    {
        if ($manifest->type !== PluginType::Translation) {
            return;
        }

        $dir = $pluginRoot.\DIRECTORY_SEPARATOR.'translations'.\DIRECTORY_SEPARATOR.'native';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir.\DIRECTORY_SEPARATOR.'*.json');
        if ($files === false) {
            throw new PluginInstallException(\sprintf('Unable to scan the native translations directory "%s".', $dir));
        }

        foreach ($files as $file) {
            $contents = is_file($file) ? file_get_contents($file) : false;
            if ($contents === false) {
                throw new PluginInstallException(\sprintf('Unable to read native translations catalog "%s".', $file));
            }

            if (!\is_array(json_decode($contents, true))) {
                throw new PluginInstallException(\sprintf('Native translations catalog "%s" is not a valid JSON object.', $file));
            }
        }
    }

    /**
     * The file to check, passed to {@see self::SYNTAX_CHECK_CODE_TEMPLATE} via the child
     * process's environment rather than as a CLI argument — see {@see PhpCliCommand}'s docblock
     * for why `$argv` cannot be used here.
     */
    private const string SYNTAX_CHECK_FILE_ENV = 'ANIMEDB_PLUGIN_SYNTAX_CHECK_FILE';

    /** @see self::assertNoSyntaxErrors() */
    private const int SYNTAX_CHECK_EXIT_OK = 0;
    private const int SYNTAX_CHECK_EXIT_UNREADABLE_FILE = 2;

    /**
     * Template for the code run via {@see PhpCliCommand::forEval()} by
     * {@see self::assertNoSyntaxErrors()} (formatted by {@see self::syntaxCheckCode()}, which
     * substitutes in {@see self::SYNTAX_CHECK_EXIT_OK} and
     * {@see self::SYNTAX_CHECK_EXIT_UNREADABLE_FILE} — kept out of this literal so the exit codes
     * the evaluated child process actually returns can never drift from the ones
     * {@see self::assertNoSyntaxErrors()} compares against). Any other exit code, in particular
     * the literal `1` below, is read by the caller as "syntax error" — see the fall-through branch
     * in {@see self::assertNoSyntaxErrors()}.
     *
     * Reports a syntax error the same way `php -l` does — a parse error with a line number —
     * without `php -l` itself, which FrankenPHP's `php-cli` subcommand cannot be made to run (see
     * {@see PhpCliCommand}'s docblock): `-l` is just another flag it does not parse.
     *
     * `token_get_all()` with the `TOKEN_PARSE` flag drives the same lexer/parser pass the engine
     * uses to compile a file, without a subprocess-flag detour, throwing a catchable `\ParseError`
     * for exactly the same grammar errors `php -l` reports (matched line-for-line against `php -l`
     * across unclosed braces, unterminated strings and misplaced tokens). What it does not
     * replicate is `php -l`'s further compile-time checks that go beyond grammar, e.g. validating
     * an attribute's allowed target — out of scope for a syntax check. Since `token_get_all()`
     * only tokenizes and parses, never compiles to opcodes, the plugin file's own code is never
     * executed by this check.
     */
    private const string SYNTAX_CHECK_CODE_TEMPLATE = <<<'PHP'
        $file = getenv('%s');
        if ($file === false || !is_file($file) || !is_readable($file)) {
            fwrite(STDERR, 'Unable to read file for syntax check.');
            exit(%d);
        }
        try {
            token_get_all(file_get_contents($file), TOKEN_PARSE);
        } catch (\ParseError $error) {
            fwrite(STDERR, $error->getMessage().' on line '.$error->getLine());
            exit(1);
        }
        exit(%d);
        PHP;

    private static function syntaxCheckCode(): string
    {
        return \sprintf(
            self::SYNTAX_CHECK_CODE_TEMPLATE,
            self::SYNTAX_CHECK_FILE_ENV,
            self::SYNTAX_CHECK_EXIT_UNREADABLE_FILE,
            self::SYNTAX_CHECK_EXIT_OK,
        );
    }

    /**
     * Checks every `*.php` file under the unpacked plugin for syntax errors (issue #250), via
     * {@see self::syntaxCheckCode()}. Custom-upload path only: marketplace plugins are already
     * checked on the registry side (issue #220), so this check has no equivalent there. Collects
     * every syntax error found instead of stopping at the first one, so a single failed install
     * reports the full picture.
     *
     * @throws PluginSyntaxErrorException
     * @throws PluginInstallException     if a file Finder just found cannot be read back (e.g. a
     *                                    permissions issue in the freshly unpacked staging
     *                                    directory) — never reported as a syntax error, since it
     *                                    is not one
     */
    private function assertNoSyntaxErrors(string $pluginRoot): void
    {
        $files = (new Finder())->files()->in($pluginRoot)->name('*.php');
        $command = PhpCliCommand::forEval(\PHP_BINARY, self::syntaxCheckCode());

        $errors = [];
        foreach ($files as $file) {
            // Finder reports the relative path with the host separator, so this reads
            // `src\Plugin.php` on Windows (caught by the Windows runtime-parity job, issue #480).
            // The value is shown to the user as "which file in the plugin you uploaded is broken",
            // and what they have in front of them is a ZIP, whose entries are `/`-separated by
            // specification. Normalising here keeps the message pointing at something the user can
            // actually find, and identical on every platform — it is display-only and never
            // matched against anything.
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            $process = new Process($command, null, [self::SYNTAX_CHECK_FILE_ENV => $file->getRealPath()]);
            $process->run();
            $exitCode = $process->getExitCode();

            if ($exitCode === self::SYNTAX_CHECK_EXIT_OK) {
                continue;
            }

            if ($exitCode === self::SYNTAX_CHECK_EXIT_UNREADABLE_FILE) {
                throw new PluginInstallException(\sprintf('Unable to read plugin file "%s" for a syntax check.', $relativePath));
            }

            // Anything else — the expected `exit(1)` for a caught \ParseError, but also any exit
            // code neither branch above expects — is reported as a syntax error rather than
            // silently accepted: this check exists to block a broken plugin from being installed,
            // so an unexpected failure of the check itself must fail closed.
            $errors[] = new PluginSyntaxError($relativePath, $this->parseSyntaxErrorMessage($process->getErrorOutput()));
        }

        if ($errors !== []) {
            throw new PluginSyntaxErrorException($errors);
        }
    }

    private function parseSyntaxErrorMessage(string $errorOutput): string
    {
        $message = trim($errorOutput);

        return $message !== '' ? $message : 'Unknown syntax error.';
    }

    private function move(string $source, string $destination): void
    {
        if (!is_dir($this->pluginsDir) && !mkdir($this->pluginsDir, recursive: true) && !is_dir($this->pluginsDir)) {
            throw new PluginInstallException(\sprintf('Unable to create plugins directory "%s".', $this->pluginsDir));
        }

        if (!@rename($source, $destination)) {
            throw new PluginInstallException(\sprintf('Unable to move "%s" to "%s".', $source, $destination));
        }
    }

    /**
     * Used only by {@see self::restoreBackup()}: unlike {@see self::move()}, a failure to swap the
     * backup back into place is not the end state to report — the whole point is to make one more
     * attempt at making the app's disk state consistent again before giving up.
     */
    private function moveWithRetries(string $source, string $destination): void
    {
        for ($attempt = 1; $attempt <= self::RESTORE_MOVE_MAX_ATTEMPTS; ++$attempt) {
            if (@rename($source, $destination)) {
                return;
            }

            if ($attempt < self::RESTORE_MOVE_MAX_ATTEMPTS) {
                usleep(self::RESTORE_MOVE_RETRY_DELAY_MICROSECONDS);
            }
        }

        throw new PluginInstallException(\sprintf('Unable to move "%s" to "%s".', $source, $destination));
    }

    /**
     * Cleanup of a staging/backup directory this installer created itself, as opposed to a plugin
     * directory rollback ({@see self::rollbackFailedMove()}, {@see self::restoreBackup()}): a
     * failure here is logged, not thrown, since it never runs from inside a catch block reacting
     * to a more important failure and leftover staging clutter is not itself a correctness problem.
     */
    private function cleanupStagingDirectory(string $dir): void
    {
        try {
            PluginDirectoryRemover::remove($dir);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to remove a plugin installer staging directory.', [
                'dir' => $dir,
                'exception' => $exception,
            ]);
        }
    }
}
