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

namespace App\Service\I18nCoverage\Github;

use App\Service\I18nCoverage\PluginTranslationKeysSource;
use App\Service\Translation\TranslationCatalog;

/**
 * Real {@see PluginTranslationKeysSource}: downloads the plugin's latest release asset via the
 * injected {@see GhReleaseZipDownloader} (never a monorepo working tree — see that interface's
 * docblock), extracts it, and reads every `translations/messages.<locale>.yaml` it contains
 * through {@see TranslationCatalog} — the same catalog reader
 * `App\Service\Translation\TranslationCoverageService::coverageForPluginDirectory()` already
 * uses for an installed plugin's own directory.
 *
 * A translation-type plugin may ship more than one locale (e.g. `animedb-language-pack` ships
 * both `de` and `ja`). The key set this returns is the *intersection* across every locale
 * catalog found, not their union: a key present in `de` but absent from `ja` still leaves the
 * plugin's translation genuinely incomplete, and unioning would silently hide that from the
 * delta this class feeds into {@see \App\Service\I18nCoverage\I18nCoverageIssueDecider}. A
 * release with no parseable locale catalog at all yields an empty key set (the app's full
 * reference set then becomes the delta), which is the correct signal for a translation plugin
 * that currently ships nothing usable.
 *
 * This intersection differs from `translation_keys_count` in the plugins monorepo's own
 * `PluginValidator`, which unions the key sets of a plugin's locales
 * (`array_unique(array_merge(...))`) for the coverage percentage shown on the marketplace
 * listing. The two currently agree because gate #58 in that repo requires every locale of a
 * plugin to ship the same key set; if #58 is ever relaxed to allow a partially translated
 * locale, the two figures will diverge on purpose — the marketplace percentage would read
 * optimistic (any locale having a key counts) while this notification would still flag the
 * plugin as missing that key (every locale must have it). That divergence is expected, not a
 * bug in either place.
 */
final class GhPluginReleaseTranslationKeysSource implements PluginTranslationKeysSource
{
    public function __construct(
        private readonly string $pluginId,
        private readonly GhReleaseZipDownloader $downloader,
    ) {
    }

    public function keys(): array
    {
        return self::keysFromZip($this->downloader->downloadLatestReleaseZip($this->pluginId));
    }

    /**
     * @return list<string> sorted intersection of the key sets of every
     *                      `translations/messages.*.yaml` file the archive contains
     */
    private static function keysFromZip(string $zipPath): array
    {
        $extractDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'i18n-coverage-extract-'.bin2hex(random_bytes(8));

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(\sprintf('Unable to open "%s" as a zip archive.', $zipPath));
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();

            throw new \RuntimeException(\sprintf('Unable to extract "%s".', $zipPath));
        }
        $zip->close();

        $keySets = [];
        foreach (glob($extractDir.'/translations/messages.*.yaml') ?: [] as $file) {
            $catalog = TranslationCatalog::loadFile($file);
            if ($catalog !== null) {
                $keySets[] = array_keys($catalog);
            }
        }

        if ($keySets === []) {
            return [];
        }

        $intersected = array_reduce(
            \array_slice($keySets, 1),
            static fn (array $carry, array $keys): array => array_intersect($carry, $keys),
            $keySets[0],
        );

        $result = array_values($intersected);
        sort($result);

        return $result;
    }
}
