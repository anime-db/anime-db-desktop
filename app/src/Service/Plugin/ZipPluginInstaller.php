<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Manifest\InvalidManifestException;
use AnimeDb\PluginContracts\Manifest\InvalidManifestJsonException;
use AnimeDb\PluginContracts\Manifest\Manifest;
use AnimeDb\PluginContracts\Manifest\ManifestParser;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;

/**
 * Installs a plugin from an uploaded ZIP archive: unpacks it into a private temporary directory
 * first, validates its `manifest.json` there, and only then moves it into
 * `%app.plugins_dir%/<pluginId>/` and re-runs {@see InstalledPluginsRegistry::reconcile()} to
 * pick it up. Any failure along the way — a corrupt archive, a missing/invalid manifest, an id
 * collision, or a filesystem error moving the unpacked directory into place — rolls back
 * whatever was created, so a failed install never leaves a partial plugin behind.
 *
 * Deliberately out of scope here (see issue #248): compatibility/lint checks on the manifest
 * beyond {@see ManifestParser::parse()}'s own validation, any UI, and activation/cache warm-up
 * (issue #222) — this service only gets as far as "files are in place and the index is
 * up to date".
 */
final class ZipPluginInstaller
{
    public function __construct(
        private readonly string $pluginsDir,
        private readonly InstalledPluginsRegistry $registry,
        private readonly ManifestParser $manifestParser = new ManifestParser(),
    ) {
    }

    /**
     * @throws InvalidInstalledPluginException if manifest.json is missing or invalid
     * @throws PluginAlreadyInstalledException if the manifest's plugin id is already installed
     * @throws PluginInstallException          if the archive cannot be unpacked or moved into place
     */
    public function install(string $zipPath): PluginId
    {
        $tmpDir = $this->createTmpDir();
        $moveStarted = false;
        $targetDir = null;

        try {
            $this->extract($zipPath, $tmpDir);
            $manifest = $this->parseManifest($tmpDir);
            $pluginId = new PluginId($manifest->id);
            $targetDir = $this->pluginsDir.\DIRECTORY_SEPARATOR.$pluginId;

            if ($this->registry->has($pluginId) || is_dir($targetDir)) {
                throw new PluginAlreadyInstalledException($pluginId);
            }

            $moveStarted = true;
            $this->move($tmpDir, $targetDir);

            $this->registry->reconcile();

            return $pluginId;
        } catch (\Throwable $exception) {
            $this->removeDirectory($tmpDir);
            if ($moveStarted && null !== $targetDir) {
                $this->removeDirectory($targetDir);
            }

            throw $exception;
        }
    }

    private function createTmpDir(): string
    {
        $tmpDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'anime-db-plugin-install-'.bin2hex(random_bytes(8));

        if (!mkdir($tmpDir, recursive: true) && !is_dir($tmpDir)) {
            throw new PluginInstallException(\sprintf('Unable to create temporary directory "%s".', $tmpDir));
        }

        return $tmpDir;
    }

    private function extract(string $zipPath, string $targetDir): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if (true !== $openResult) {
            throw new PluginInstallException(\sprintf('Unable to open ZIP archive "%s" (error code %s).', $zipPath, $openResult));
        }

        try {
            if (!$zip->extractTo($targetDir)) {
                throw new PluginInstallException(\sprintf('Unable to extract ZIP archive "%s".', $zipPath));
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws InvalidInstalledPluginException
     */
    private function parseManifest(string $dir): Manifest
    {
        $manifestPath = $dir.\DIRECTORY_SEPARATOR.'manifest.json';
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if (false === $contents) {
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
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
