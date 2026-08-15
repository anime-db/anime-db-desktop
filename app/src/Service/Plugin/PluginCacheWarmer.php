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

use App\Service\Plugin\Exception\PluginCacheWarmupException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Compiles the DI container in a separate OS process, with `%app.plugins_dir%`'s current
 * contents on disk (issue #222) — a shared step for every plugin activation path (marketplace
 * install issue #220, custom ZIP install {@see ZipPluginInstaller}, future plugin update issue
 * #224): it catches a plugin that crashes or hangs the kernel bootstrap before that ever reaches
 * the app's own worker process, rather than after.
 *
 * This is a process-boundary safety net against a broken plugin, not a security sandbox: PHP has
 * no language-level capability-based security, so nothing here stops a plugin from doing anything
 * a normal PHP script can do once it is actually loaded by the worker on every later request.
 *
 * Runs the exact same `bin/console cache:warmup` a real boot would run, with `APP_RUNTIME_DIR`
 * pointed at a throwaway directory instead of the app's real one — so it warms the same container
 * the worker would end up loading, not a stripped-down bootstrap that would miss whatever the real
 * one breaks on. `APP_ENV`/`APP_DEBUG` are pinned to `prod`/`0` regardless of the calling
 * process's own environment, since that is what the worker always runs with in production
 * (`native/supervisor/env.js`) — the point is to validate what will actually run for users.
 *
 * There is deliberately no atomic swap of the result into the real cache directory: a running
 * FrankenPHP worker holds its currently compiled container open (Windows cannot rename a
 * directory with open file handles), and the set of plugin bundles gets baked into the dumped
 * `<Container>.bundles.php` at first compilation anyway (see the docblock on
 * {@see \App\Kernel::initializeBundles()}), so a stale worker could not pick up a swapped-in
 * container without restarting regardless. On success the throwaway directory is simply thrown
 * away and the real cache is invalidated via `native/supervisor/cache-invalidation.js` (issue
 * #386) by the caller's surrounding install flow, so the next app start compiles a fresh
 * container with the activated plugin included.
 *
 * Fails closed: a non-zero exit code, a timeout, or a zero exit code that still did not leave a
 * compiled container behind (issue #392 compiles the container at every migration-time boot too,
 * so a plugin that only breaks the bootstrap would otherwise block the whole app from starting,
 * not just fail to load) all raise {@see PluginCacheWarmupException} with the child process's
 * captured output attached, and the throwaway directory is always removed afterwards regardless
 * of the outcome.
 */
final class PluginCacheWarmer implements PluginCacheWarmerInterface
{
    /**
     * Same staging location as {@see ZipPluginInstaller}: a sibling of `%app.plugins_dir%`, not
     * the system temp directory, for the same reason documented on
     * {@see ZipPluginInstaller::stagingRootDir()} — it must stay outside `%app.plugins_dir%` so
     * {@see InstalledPluginsRegistry::reconcile()} never mistakes it for a plugin candidate.
     */
    private const string STAGING_DIR_NAME = '.plugin-install-tmp';

    /**
     * 60 seconds. This is a one-off boot of the same kernel a normal request boots, not a
     * long-running task — for comparison, `native/supervisor/php-command.js`'s one-off console
     * calls budget 30s for `messenger:setup-transports`, 10 minutes for migrations and 30 minutes
     * for search reindexing (issue #400). A cold container compile plus cache warm-up is much
     * closer to the first of those, with headroom for a slow disk or a plugin that does real work
     * during warm-up (e.g. registering routes).
     */
    private const int TIMEOUT_SECONDS = 60;

    /** Trailing process output kept in a failure message — enough to diagnose, not a full dump. */
    private const int OUTPUT_TAIL_CHARS = 4000;

    private const int REMOVE_DIR_MAX_ATTEMPTS = 5;
    private const int REMOVE_DIR_RETRY_DELAY_MICROSECONDS = 200_000;

    public function __construct(
        private readonly string $pluginsDir,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PluginCacheWarmupException
     */
    public function warmUp(): void
    {
        $runtimeDir = $this->createTmpDir();

        try {
            $process = new Process(
                PhpCliCommand::build(\PHP_BINARY, $this->consolePath(), 'cache:warmup'),
                null,
                ['APP_RUNTIME_DIR' => $runtimeDir, 'APP_ENV' => 'prod', 'APP_DEBUG' => '0'],
            );
            $process->setTimeout(self::TIMEOUT_SECONDS);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                // The process was already killed by Process::checkTimeout(); isSuccessful()
                // below reports the failure the same way a non-zero exit code would.
            }

            if (!$process->isSuccessful() || !$this->containerWasDumped($runtimeDir)) {
                $output = $this->tail($process->getOutput().$process->getErrorOutput());

                $this->logger->error('Plugin cache warm-up failed.', [
                    'exitCode' => $process->getExitCode(),
                    'output' => $output,
                ]);

                throw new PluginCacheWarmupException($output);
            }
        } finally {
            $this->removeDirectory($runtimeDir);
        }
    }

    private function consolePath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'console';
    }

    /**
     * A zero exit code alone is not proof the container was actually dumped — the process could
     * have exited before reaching that point. The container class name depends on env/debug,
     * which are pinned above, but matching on the `Container.php` suffix Symfony always uses
     * avoids hardcoding that exact class name here as well.
     */
    private function containerWasDumped(string $runtimeDir): bool
    {
        $matches = glob($runtimeDir.\DIRECTORY_SEPARATOR.'cache'.\DIRECTORY_SEPARATOR.'*Container.php');

        return $matches !== false && $matches !== [];
    }

    private function tail(string $output): string
    {
        return \strlen($output) > self::OUTPUT_TAIL_CHARS ? substr($output, -self::OUTPUT_TAIL_CHARS) : $output;
    }

    private function stagingRootDir(): string
    {
        return \dirname($this->pluginsDir).\DIRECTORY_SEPARATOR.self::STAGING_DIR_NAME;
    }

    private function createTmpDir(): string
    {
        $tmpDir = $this->stagingRootDir().\DIRECTORY_SEPARATOR.'anime-db-cache-warmup-'.bin2hex(random_bytes(8));

        if (!mkdir($tmpDir, recursive: true) && !is_dir($tmpDir)) {
            throw new PluginCacheWarmupException(\sprintf('Unable to create temporary directory "%s".', $tmpDir));
        }

        return $tmpDir;
    }

    /**
     * As a diagnostic artefact the warm-up directory is nearly worthless (a failed bootstrap
     * usually leaves an unfinished container dump behind, not a complete one) but it is sizeable,
     * so it is always removed, on both success and failure. Unlike
     * {@see ZipPluginInstaller::removeDirectory()}, this retries: the child process that just
     * populated this directory may not have released every file handle the instant it exits
     * (especially after being force-killed on a timeout), and Windows refuses to delete a file
     * that is still held open. A failure to remove is logged and otherwise swallowed — it must
     * not turn a successful (or already-reported-failed) warm-up into a hard error.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        for ($attempt = 1; $attempt <= self::REMOVE_DIR_MAX_ATTEMPTS; ++$attempt) {
            if ($this->tryRemoveDirectory($dir)) {
                return;
            }

            if ($attempt < self::REMOVE_DIR_MAX_ATTEMPTS) {
                usleep(self::REMOVE_DIR_RETRY_DELAY_MICROSECONDS);
            }
        }

        $this->logger->warning('Unable to remove plugin cache warm-up temporary directory.', ['dir' => $dir]);
    }

    private function tryRemoveDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && !is_link($path)) {
                if (!$this->tryRemoveDirectory($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }

        return @rmdir($dir);
    }
}
