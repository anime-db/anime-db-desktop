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

namespace App\Service;

use App\Entity\Enum\PaginationMode;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;

/**
 * Reads and writes user-facing app settings in %AppData%/config.json, the same file
 * native/config.js writes appSecret and locale (issue #85) to. Missing file/key/unreadable JSON
 * all fall back to the documented default (infinite scroll, issue #74) rather than failing the
 * request.
 */
final class AppSettingsProvider
{
    public function __construct(private readonly string $configPath)
    {
    }

    public function getPaginationMode(): PaginationMode
    {
        $data = $this->readConfig();
        $mode = $data['paginationMode'] ?? null;

        if (!\is_string($mode)) {
            return PaginationMode::InfiniteScroll;
        }

        return PaginationMode::tryFrom($mode) ?? PaginationMode::InfiniteScroll;
    }

    public function getLocale(): ?string
    {
        $locale = $this->readConfig()['locale'] ?? null;

        return \is_string($locale) ? $locale : null;
    }

    /**
     * Overwrites the locale field in place, keeping every other key (appSecret, paginationMode,
     * ...) untouched — the same read-modify-write pattern native/config.js uses.
     */
    public function setLocale(string $locale): void
    {
        $config = $this->readConfig();
        $config['locale'] = $locale;

        $this->writeConfig($config);
    }

    /**
     * The plugin whose {@see \AnimeDb\PluginContracts\Search\SearchByPluginInterface} implementation is
     * used by default, e.g. "animedb-shikimori". Null once none is configured yet or the
     * configured id no longer names an installed search plugin — the caller
     * ({@see Plugin\DefaultSearchPluginRegistry}) is what actually cascades to the
     * next available one and persists it back via {@see self::setDefaultSearchPluginId()}. A
     * malformed value (hand-edited config.json) is treated the same as "not configured", not a
     * fatal error.
     */
    public function getDefaultSearchPluginId(): ?PluginId
    {
        $id = $this->readConfig()['defaultSearchPluginId'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }

        try {
            return new PluginId($id);
        } catch (InvalidPluginIdException) {
            return null;
        }
    }

    /**
     * Overwrites the defaultSearchPluginId field in place, same read-modify-write pattern as
     * {@see self::setLocale()}. Null clears the setting (no search plugin configured).
     */
    public function setDefaultSearchPluginId(?PluginId $pluginId): void
    {
        $config = $this->readConfig();
        $config['defaultSearchPluginId'] = $pluginId !== null ? (string) $pluginId : null;

        $this->writeConfig($config);
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        $directory = \dirname($this->configPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents(
            $this->configPath,
            json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        $contents = file_get_contents($this->configPath);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);

        return \is_array($data) ? $data : [];
    }
}
