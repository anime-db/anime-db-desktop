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
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginSyntaxErrorException;
use Composer\Semver\Semver;
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
 * Also lints every unpacked `*.php` file with `php -l` (issue #250), still before anything is
 * moved into place — see {@see self::assertNoSyntaxErrors()}. This is specific to the custom
 * (untrusted ZIP upload) path: marketplace plugins are linted on the registry side (issue #220)
 * and never go through this service.
 *
 * After the plugin is moved into place and the registry re-synced, runs {@see PluginCacheWarmer}
 * (issue #222) to compile the DI container with the new plugin present, in an isolated process —
 * this is what actually gets to decide whether the install as a whole succeeds, on top of
 * everything checked above.
 *
 * Deliberately still out of scope here: any UI, and enabling an already-installed plugin.
 */
final class ZipPluginInstaller
{
    private const STAGING_DIR_NAME = '.plugin-install-tmp';

    public function __construct(
        private readonly string $pluginsDir,
        private readonly string $coreVersion,
        private readonly InstalledPluginsRegistry $registry,
        private readonly PluginCacheWarmerInterface $cacheWarmer,
        private readonly ManifestParser $manifestParser = new ManifestParser(),
    ) {
    }

    /**
     * @throws InvalidInstalledPluginException        if manifest.json is missing or invalid
     * @throws IncompatiblePluginCoreVersionException if the current core version does not satisfy
     *                                                the manifest's `require.core` lower bound
     * @throws PluginSyntaxErrorException             if any `*.php` file in the archive has a PHP syntax error
     * @throws PluginAlreadyInstalledException        if the manifest's plugin id is already installed
     * @throws PluginInstallException                 if the archive cannot be unpacked or moved into place
     * @throws Exception\PluginCacheWarmupException   if the isolated cache warm-up fails to
     *                                                compile the DI container with the new
     *                                                plugin present
     */
    public function install(string $zipPath): PluginId
    {
        $tmpDir = $this->createTmpDir();
        $moveStarted = false;
        $targetDir = null;

        try {
            $this->extract($zipPath, $tmpDir);
            $pluginRoot = $this->resolvePluginRoot($tmpDir);
            $manifest = $this->parseManifest($pluginRoot);
            $this->assertCoreVersionCompatible($manifest);
            $this->assertNoSyntaxErrors($pluginRoot);
            $pluginId = new PluginId($manifest->id);
            $targetDir = $this->pluginsDir.\DIRECTORY_SEPARATOR.$pluginId;

            if ($this->registry->has($pluginId) || is_dir($targetDir)) {
                throw new PluginAlreadyInstalledException($pluginId);
            }

            $moveStarted = true;
            $this->move($pluginRoot, $targetDir);

            $this->registry->reconcile();

            $this->cacheWarmer->warmUp();

            return $pluginId;
        } catch (\Throwable $exception) {
            if ($moveStarted && $targetDir !== null) {
                $this->removeDirectory($targetDir);
                // The index above may already have been rewritten with an entry pointing at the
                // directory just removed (e.g. a cache warm-up failure, which runs after
                // reconcile()) — re-sync it so a stale entry does not outlive the rollback.
                $this->registry->reconcile();
            }

            throw $exception;
        } finally {
            $this->removeDirectory($tmpDir);
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
     * @throws IncompatiblePluginCoreVersionException
     */
    private function assertCoreVersionCompatible(Manifest $manifest): void
    {
        if (!Semver::satisfies($this->coreVersion, $manifest->require->core)) {
            throw new IncompatiblePluginCoreVersionException($manifest->require->core, $this->coreVersion);
        }
    }

    /**
     * Lints every `*.php` file under the unpacked plugin with `php -l` (issue #250). Custom-upload
     * path only: marketplace plugins are already linted on the registry side (issue #220), so this
     * check has no equivalent there. Collects every syntax error found instead of stopping at the
     * first one, so a single failed install reports the full picture.
     *
     * @throws PluginSyntaxErrorException
     */
    private function assertNoSyntaxErrors(string $pluginRoot): void
    {
        $files = (new Finder())->files()->in($pluginRoot)->name('*.php');

        $errors = [];
        foreach ($files as $file) {
            $process = new Process(PhpCliCommand::build(\PHP_BINARY, '-l', $file->getRealPath()));
            $process->run();

            if (!$process->isSuccessful()) {
                $errors[] = new PluginSyntaxError($file->getRelativePathname(), $this->parseSyntaxErrorMessage($process->getErrorOutput(), $process->getOutput()));
            }
        }

        if ($errors !== []) {
            throw new PluginSyntaxErrorException($errors);
        }
    }

    /**
     * `php -l` writes its parse error to stderr as e.g. `PHP Parse error:  syntax error, ...
     * in /abs/path/file.php on line 5`, followed by an `Errors parsing /abs/path/file.php` line on
     * stdout. Only the first line carries the actual message, so that is all this keeps. Whether the
     * message lands on stderr or stdout depends on the `display_errors`/`log_errors` ini settings
     * (ours are loaded from a native-supplied `PHPRC`, which may differ from the CLI defaults), so
     * stdout is used as a fallback when stderr is empty. The trailing ` in /abs/path/file.php` is
     * stripped since the file is already known to the caller via {@see PluginSyntaxError::$relativePath}
     * and the temp staging path it contains would be meaningless to the user — the line number is kept.
     */
    private function parseSyntaxErrorMessage(string $errorOutput, string $standardOutput): string
    {
        $firstLine = strtok(trim(trim($errorOutput) !== '' ? $errorOutput : $standardOutput), "\n");
        if ($firstLine === false) {
            return 'Unknown syntax error.';
        }

        return preg_replace('/ in .+( on line \d+)$/', '$1', $firstLine) ?? $firstLine;
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
