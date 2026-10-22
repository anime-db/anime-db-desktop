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

namespace App\Service\Storage;

use App\Entity\Storage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Gives a writable storage a static icon in Explorer: `autorun.inf` (icon + volume label) when the
 * storage is a drive root, an `IconResource` in the folder's `desktop.ini` (next to the marker) otherwise.
 * The icon file is copied into the storage root and referred to by its relative name, so it survives
 * the storage moving between drive letters and machines.
 *
 * Anything is only ever written to, or removed from, a root when the root's marker names the storage
 * AND the entry points at one of our icon names; a foreign `autorun.inf` or icon is left untouched.
 * Failures are logged as warnings and never thrown.
 */
final class StorageIconService
{
    private const AUTORUN_FILENAME = 'autorun.inf';

    /** @var array<string, string> */
    private const ICONS = [
        'folder' => 'animedb-folder.ico',
        'external' => 'animedb-external.ico',
    ];

    /** @var \Closure(string): bool */
    private readonly \Closure $isDriveRoot;

    /**
     * @param ?callable(string): bool $isDriveRoot overrides the drive-root test — tests inject one, since a drive
     *                                             root does not exist on the Linux CI runner
     */
    public function __construct(
        private readonly StorageMarkerService $markerService,
        private readonly AutorunInfReader $autorunReader,
        private readonly string $iconsDir,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $isDriveRoot = null,
    ) {
        $this->isDriveRoot = $isDriveRoot !== null
            ? $isDriveRoot(...)
            : static fn (string $path): bool => preg_match('/^[A-Za-z]:\\\\?$/', $path) === 1;
    }

    /** Puts the icon of $storage's type into its root and points Explorer at it. Call only after a successful reconcile(). */
    public function apply(Storage $storage): void
    {
        $icon = self::ICONS[$storage->getType()->value] ?? null;

        if ($icon === null || !$storage->getType()->isWritable() || $storage->getPath() === null) {
            return;
        }

        try {
            $path = $storage->requirePath();

            if ($storage->id === null || $this->markerService->readMarkerId($path) !== $storage->id) {
                return;
            }

            if (($this->isDriveRoot)($path)) {
                $this->applyToDriveRoot($storage, $path, $icon);
            } else {
                $this->applyToFolder($path, $icon);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to apply the storage icon', ['storage' => $storage->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Takes back what apply() put at $path (defaults to the storage's own path; the old path when the storage
     * is being moved). Must run before StorageMarkerService::forget(), which erases the marker this relies on.
     */
    public function remove(Storage $storage, ?string $path = null): void
    {
        try {
            $path ??= $storage->requirePath();

            if ($storage->id === null || $this->markerService->readMarkerId($path) !== $storage->id) {
                return;
            }

            $autorun = $this->rootFile($path, self::AUTORUN_FILENAME);
            if ($this->isOurAutorun($autorun)) {
                $this->deleteFile($autorun);
            }

            $info = $this->markerService->readShellClassInfo($path);
            if ($info !== null && isset($info['iconresource']) && self::isOurIcon($info['iconresource'])) {
                $this->markerService->setShellClassInfoValue($path, 'IconResource', null);
            }

            foreach (self::ICONS as $name) {
                $this->deleteFile($this->rootFile($path, $name));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to remove the storage icon', ['storage' => $storage->id, 'error' => $e->getMessage()]);
        }
    }

    /** True when $value (an `icon=` / `IconResource=` value) names one of our icon files: quotes, `.\` prefix and `,N` suffix are ignored. */
    public static function isOurIcon(string $value): bool
    {
        return \in_array(self::normalizeIconValue($value), self::ICONS, true);
    }

    public static function normalizeIconValue(string $value): string
    {
        $value = trim($value, " \t\"'");
        $value = preg_replace('/\s*,\s*-?\d+$/', '', $value) ?? $value;
        $value = trim($value, " \t\"'");
        $value = preg_replace('#^\.[\\\\/]#', '', $value) ?? $value;

        return strtolower($value);
    }

    private function applyToDriveRoot(Storage $storage, string $path, string $icon): void
    {
        $autorun = $this->rootFile($path, self::AUTORUN_FILENAME);

        if (is_file($autorun) && !$this->isOurAutorun($autorun)) {
            return;
        }

        if (!$this->placeIcon($path, $icon)) {
            return;
        }

        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $storage->getName()) ?? $storage->getName());
        $content = "\xFF\xFE".mb_convert_encoding("[autorun]\r\nicon=$icon\r\nlabel=$label", 'UTF-16LE', 'UTF-8');

        if (is_file($autorun) && file_get_contents($autorun) === $content) {
            return;
        }

        $this->writeProtected($autorun, $content);
    }

    private function applyToFolder(string $path, string $icon): void
    {
        $info = $this->markerService->readShellClassInfo($path);

        if ($info === null) {
            return;
        }

        // A foreign icon — IconResource, or the old IconFile scheme — is not ours to override.
        $foreign = isset($info['iconresource']) ? !self::isOurIcon($info['iconresource']) : isset($info['iconfile']);

        if ($foreign || !$this->placeIcon($path, $icon)) {
            return;
        }

        $value = "$icon,0";

        if (($info['iconresource'] ?? null) === $value) {
            return;
        }

        if ($this->markerService->setShellClassInfoValue($path, 'IconResource', $value)) {
            // Explorer reads desktop.ini only of a folder with the read-only (or system) attribute.
            $this->attrib('+R', $path);
        }
    }

    /** Makes $icon (and no other of our icons) the one in the root; false when it could not be put there. */
    private function placeIcon(string $path, string $icon): bool
    {
        foreach (self::ICONS as $name) {
            if ($name !== $icon) {
                $this->deleteFile($this->rootFile($path, $name));
            }
        }

        $source = $this->iconsDir.\DIRECTORY_SEPARATOR.$icon;
        $target = $this->rootFile($path, $icon);

        if (is_file($target) && is_file($source) && @md5_file($target) === md5_file($source)) {
            return true;
        }

        if (is_file($target)) {
            $this->attrib('-S -H', $target);
        }

        if (!@copy($source, $target)) {
            $this->logger->warning('Failed to copy the storage icon', ['source' => $source, 'target' => $target]);

            return false;
        }

        $this->attrib('+S +H', $target);

        return true;
    }

    /** An `autorun.inf` is ours when its readable `icon=` names one of our icons (the caller has already matched the marker). */
    private function isOurAutorun(string $autorun): bool
    {
        $bytes = is_file($autorun) ? @file_get_contents($autorun) : false;

        if ($bytes === false) {
            return false;
        }

        $icon = $this->autorunReader->getValue($bytes, 'autorun', 'icon');

        return $icon !== null && self::isOurIcon($icon);
    }

    private function writeProtected(string $file, string $content): void
    {
        if (is_file($file)) {
            $this->attrib('-S -H', $file);
        }

        if (@file_put_contents($file, $content) === false) {
            $this->logger->warning('Failed to write the storage icon file', ['path' => $file]);
        }

        if (is_file($file)) {
            $this->attrib('+S +H', $file);
        }
    }

    private function deleteFile(string $file): void
    {
        if (is_file($file) && !@unlink($file)) {
            $this->logger->warning('Failed to delete the storage icon file', ['path' => $file]);
        }
    }

    private function rootFile(string $storagePath, string $name): string
    {
        return rtrim($storagePath, '\\/').\DIRECTORY_SEPARATOR.$name;
    }

    /** Attributes only exist on Windows (the app ships for Windows; the Linux CI runner has no `attrib`). */
    private function attrib(string $flags, string $path): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            exec("attrib $flags ".escapeshellarg($path));
        }
    }
}
