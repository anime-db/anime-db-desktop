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

use AnimeDb\PluginContracts\Manifest\PluginType;

/**
 * Extends the built-in locale set (`app.locales`) with locales declared by enabled
 * {@see PluginType::Translation} plugins (issue #453) — {@see PluginType::Integration} manifests
 * cannot declare `locales` at all (see `ManifestValidator`), so they never contribute here, only
 * to their own translation domain via {@see PluginLoader::translationPaths()}.
 *
 * Recomputed on every call, deliberately not cached across requests: FrankenPHP worker mode runs
 * a pool of worker processes with no shared memory between them (see {@see \App\Service\WsPublisher}),
 * so an in-process cache invalidated by an in-process event only updates the one worker that
 * happened to handle the mutating request, leaving the rest stale until they eventually restart.
 *
 * This is a deliberate, documented relaxation of the "no file I/O on the request path" constraint
 * issue #84 originally established when it rejected `glob()`-scanning `app/translations/`:
 * {@see \App\EventSubscriber\LocaleSubscriber} calls `all()` on every main request, and each call
 * costs one `require` of the pre-parsed plugin index (`installed-plugins.php`) plus one read and
 * `json_decode()` of `plugins.json` — a fixed cost independent of how many plugins are installed
 * (see {@see InstalledPluginsRegistry::readIndex()}), not the `glob()` + per-manifest parsing
 * issue #84 ruled out, but not zero either. A `filemtime()`-gated cache was considered instead and
 * rejected: `filemtime()` only has whole-second resolution, so two mutations of the same file
 * within one second would leave a stale worker undetected, which would silently reintroduce the
 * cross-worker staleness window the in-process cache above was removed for. See
 * `.claude-docs/decisions.md` (issue #84) for the full record.
 */
final class AvailableLocalesProvider
{
    /**
     * @param list<string> $coreLocales
     */
    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly array $coreLocales,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $locales = $this->coreLocales;

        foreach ($this->registry->enabled() as $plugin) {
            if ($plugin->manifest->type !== PluginType::Translation) {
                continue;
            }

            foreach ($plugin->manifest->locales ?? [] as $locale) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }
}
