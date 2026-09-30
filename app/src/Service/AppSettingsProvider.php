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

namespace App\Service;

use App\Entity\Enum\PaginationMode;
use App\Entity\Enum\ThemePreference;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;

/**
 * Reads and writes user-facing app settings in %AppData%/config.json, the same file
 * native/config.js writes appSecret and locale (issue #85) to. Missing file/key/unreadable JSON
 * all fall back to the documented default (infinite scroll, issue #74) rather than failing the
 * request.
 *
 * Every write goes through {@see AppConfigStore}, which holds an exclusive lock for the whole
 * read-modify-write cycle so a concurrent write to a different key (e.g. ProxyConfigProvider's,
 * or this class's own defaultSearchPluginId next to locale) cannot be lost (issue #342).
 */
final class AppSettingsProvider
{
    /**
     * The eight filter-panel sections (issue #666/#820) — the only keys
     * getCollapsedFilterSections()/setCollapsedFilterSections() accept; anything else in
     * config.json under "collapsedFilterSections" (a stale key from a renamed section, hand
     * edited garbage) is silently dropped rather than surfaced as a section that can never be
     * expanded again.
     */
    private const array FILTER_SECTION_KEYS = [
        'watch_status', 'type', 'date_premiere', 'user_rating', 'labels', 'genres', 'themes', 'studios',
    ];

    public function __construct(private readonly AppConfigStore $configStore)
    {
    }

    public function getPaginationMode(): PaginationMode
    {
        $data = $this->configStore->read();
        $mode = $data['paginationMode'] ?? null;

        if (!\is_string($mode)) {
            return PaginationMode::InfiniteScroll;
        }

        return PaginationMode::tryFrom($mode) ?? PaginationMode::InfiniteScroll;
    }

    public function setPaginationMode(PaginationMode $mode): void
    {
        $this->configStore->update(static function (array $config) use ($mode): array {
            $config['paginationMode'] = $mode->value;

            return $config;
        });
    }

    public function getLocale(): ?string
    {
        $locale = $this->configStore->read()['locale'] ?? null;

        return \is_string($locale) ? $locale : null;
    }

    /**
     * Overwrites the locale field in place, keeping every other key (appSecret, paginationMode,
     * ...) untouched — the same read-modify-write pattern native/config.js uses, now atomic
     * end-to-end via {@see AppConfigStore::update()}.
     */
    public function setLocale(string $locale): void
    {
        $this->configStore->update(static function (array $config) use ($locale): array {
            $config['locale'] = $locale;

            return $config;
        });
    }

    /**
     * Defaults to System — the color-mode.js behavior before this setting existed (issue #638),
     * so an existing config.json with no themePreference key keeps following prefers-color-scheme.
     */
    public function getThemePreference(): ThemePreference
    {
        $theme = $this->configStore->read()['themePreference'] ?? null;

        if (!\is_string($theme)) {
            return ThemePreference::System;
        }

        return ThemePreference::tryFrom($theme) ?? ThemePreference::System;
    }

    public function setThemePreference(ThemePreference $theme): void
    {
        $this->configStore->update(static function (array $config) use ($theme): array {
            $config['themePreference'] = $theme->value;

            return $config;
        });
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
        $id = $this->configStore->read()['defaultSearchPluginId'] ?? null;
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
        $this->configStore->update(static function (array $config) use ($pluginId): array {
            $config['defaultSearchPluginId'] = $pluginId !== null ? (string) $pluginId : null;

            return $config;
        });
    }

    /**
     * Root directory the qBittorrent download service (issue #346) is jailed to — every torrent
     * is saved under it, and a completed torrent's reported path must fall inside it before
     * AnimeDownloadLinker will read anything from it. Defaults to "%USERPROFILE%\Downloads"
     * (falling back to $HOME, then the system temp dir, on the non-Windows CI/dev environment
     * this test suite runs in) until the user picks a different folder on the settings page.
     */
    public function getDownloadsRoot(): string
    {
        $root = $this->configStore->read()['downloadsRoot'] ?? null;

        return \is_string($root) && $root !== '' ? $root : $this->defaultDownloadsRoot();
    }

    public function setDownloadsRoot(string $root): void
    {
        $this->configStore->update(static function (array $config) use ($root): array {
            $config['downloadsRoot'] = $root;

            return $config;
        });
    }

    private function defaultDownloadsRoot(): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: sys_get_temp_dir();

        return rtrim($home, '\\/').\DIRECTORY_SEPARATOR.'Downloads';
    }

    /**
     * Whether the user has opted into the Windows-only "allow incoming torrent connections"
     * firewall toggle (issue #361). Defaults to false — the app and qbittorrent-nox run
     * outbound-only, admin-free, until the user explicitly turns this on from the settings page.
     */
    public function getIncomingConnectionsAllowed(): bool
    {
        $allowed = $this->configStore->read()['incomingConnectionsAllowed'] ?? null;

        return $allowed === true;
    }

    public function setIncomingConnectionsAllowed(bool $allowed): void
    {
        $this->configStore->update(static function (array $config) use ($allowed): array {
            $config['incomingConnectionsAllowed'] = $allowed;

            return $config;
        });
    }

    /**
     * Which of the eight catalog filter-panel sections are collapsed (issue #820), by section
     * key — e.g. "genres", "studios". Missing key or unreadable JSON both mean "every section
     * expanded", the behavior before this setting existed, same fallback shape as
     * getPaginationMode().
     *
     * @return list<string>
     */
    public function getCollapsedFilterSections(): array
    {
        $sections = $this->configStore->read()['collapsedFilterSections'] ?? null;
        if (!\is_array($sections)) {
            return [];
        }

        return $this->filterKnownSections($sections);
    }

    /**
     * @param array<mixed> $sections
     */
    public function setCollapsedFilterSections(array $sections): void
    {
        $filtered = $this->filterKnownSections($sections);

        $this->configStore->update(static function (array $config) use ($filtered): array {
            $config['collapsedFilterSections'] = $filtered;

            return $config;
        });
    }

    /**
     * @param array<mixed> $sections
     *
     * @return list<string>
     */
    private function filterKnownSections(array $sections): array
    {
        $known = array_values(array_filter(
            $sections,
            static fn (mixed $section): bool => \is_string($section) && \in_array($section, self::FILTER_SECTION_KEYS, true),
        ));

        /* @var list<string> */
        return array_values(array_unique($known));
    }
}
