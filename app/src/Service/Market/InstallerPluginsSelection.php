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

/**
 * The installer's plugin selection file: `{"plugins": ["vendor-name", ...]}`, a list of plugin ids
 * and nothing else (no versions — the version is picked from the market snapshot). The file
 * exists only while some selected plugins are still not installed.
 */
final class InstallerPluginsSelection
{
    public function __construct(private readonly string $installerPluginsPath)
    {
    }

    public function exists(): bool
    {
        return is_file($this->installerPluginsPath);
    }

    /**
     * Raw list entries as stored (they are not validated here). `null` when the file is missing.
     *
     * @return list<mixed>|null
     *
     * @throws \RuntimeException when the file cannot be read
     * @throws \JsonException    when the file is not valid JSON of the expected shape
     */
    public function read(): ?array
    {
        if (!is_file($this->installerPluginsPath)) {
            return null;
        }

        $content = @file_get_contents($this->installerPluginsPath);
        if ($content === false) {
            throw new \RuntimeException(\sprintf('Cannot read the plugin selection file "%s".', $this->installerPluginsPath));
        }

        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !isset($data['plugins']) || !\is_array($data['plugins'])) {
            throw new \JsonException('The plugin selection file has no "plugins" list.');
        }

        return array_values($data['plugins']);
    }

    /**
     * Valid, non-empty ids only — for display; anything else in the file is ignored. Never throws.
     *
     * @return list<string>
     */
    public function readIds(): array
    {
        try {
            $entries = $this->read();
        } catch (\RuntimeException|\JsonException) {
            return [];
        }

        return array_values(array_unique(array_filter($entries ?? [], static fn (mixed $entry): bool => \is_string($entry) && $entry !== '')));
    }

    /**
     * Replaces the file content atomically (temp file + rename), or removes the file for an empty list.
     *
     * @param array<mixed> $entries
     *
     * @throws \RuntimeException
     */
    public function write(array $entries): void
    {
        if ($entries === []) {
            if (is_file($this->installerPluginsPath) && !@unlink($this->installerPluginsPath)) {
                throw new \RuntimeException(\sprintf('Cannot remove the plugin selection file "%s".', $this->installerPluginsPath));
            }

            return;
        }

        $tmp = $this->installerPluginsPath.'.tmp';
        if (@file_put_contents($tmp, json_encode(['plugins' => array_values($entries)], \JSON_THROW_ON_ERROR)) === false || !@rename($tmp, $this->installerPluginsPath)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('Cannot write the plugin selection file "%s".', $this->installerPluginsPath));
        }
    }
}
