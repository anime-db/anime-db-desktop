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

namespace App\Service\Translation;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;

/**
 * Compares a translation plugin's catalog against the app's own reference key set for the
 * `messages` domain (issue #512): the app and a `translation`-type plugin share that one
 * namespace, but nothing else checks whether the plugin's key set — or its `%name%` placeholders
 * on the keys it does carry — actually matches the app's.
 *
 * The reference is read directly from `translations/messages.en.yaml`, never from the Symfony
 * Translator's compiled catalogue: {@see \App\Kernel::configureContainer()} feeds
 * {@see \App\Service\Plugin\PluginLoader::translationPaths()} into
 * `framework.translator.paths`, so the Translator's own `messages` catalogue already has every
 * enabled translation plugin's keys merged in — comparing against that would be comparing the
 * reference against itself. `CatalogTranslationsTest` guarantees every core locale (`en`, `ru`)
 * shares the same key set, so `en` alone stands in for "the app's key set" regardless of which
 * locale a plugin is being checked against.
 *
 * Console-only by design: reading and YAML-parsing a plugin's catalog on every request is exactly
 * what issue #84 ruled out for the request path (see {@see \App\Service\Plugin\AvailableLocalesProvider}
 * for the precedent). This service is wired into {@see \App\Command\TranslationsCoverageCommand}
 * and, in a later issue, a settings-page action — never into
 * {@see \App\EventSubscriber\LocaleSubscriber} or anything else on the main request path.
 */
final class TranslationCoverageService
{
    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Unlike {@see coverageForPluginDirectory()}, this also cross-checks against the manifest's own
     * `locales` field — the source of truth {@see \App\Service\Plugin\AvailableLocalesProvider} uses
     * to decide which locales the settings-page locale switcher offers for an installed plugin. A
     * locale the manifest declares but whose `messages.<locale>.yaml` is missing on disk is exactly
     * the user-facing defect this report exists to catch (the switcher offers a locale the plugin
     * never shipped a catalog for), so it gets an `unknown` entry rather than being silently absent
     * from the report the way a locale nobody declared would be.
     *
     * @return array<string, LocaleTranslationCoverage>|null keyed by locale, or null when no
     *                                                       plugin with this id is installed
     */
    public function coverageForInstalledPlugin(PluginId $id): ?array
    {
        $plugin = $this->registry->get($id);
        if ($plugin === null) {
            return null;
        }

        $coverage = $this->coverageForPluginDirectory($plugin->installPath);

        foreach ($plugin->manifest->locales ?? [] as $locale) {
            if (!isset($coverage[$locale])) {
                $coverage[$locale] = LocaleTranslationCoverage::unknown($locale);
            }
        }

        ksort($coverage);

        return $coverage;
    }

    /**
     * Reads $pluginDir straight off disk rather than through {@see InstalledPluginsRegistry} —
     * $pluginDir need not be among the installed plugins at all, which is the point: it lets a
     * plugin developer check a checkout that lives next to this app's own before ever packaging
     * or installing it.
     *
     * @return array<string, LocaleTranslationCoverage> keyed by locale, one entry per
     *                                                  `messages.<locale>.yaml` found under
     *                                                  $pluginDir/translations/
     */
    public function coverageForPluginDirectory(string $pluginDir): array
    {
        $reference = TranslationCatalog::loadFile($this->referenceCatalogPath()) ?? [];

        $coverage = [];
        $translationsDir = $pluginDir.\DIRECTORY_SEPARATOR.'translations';
        foreach (glob($translationsDir.'/messages.*.yaml') ?: [] as $file) {
            if (preg_match('/^messages\.([a-zA-Z_-]+)\.yaml$/', basename($file), $matches) !== 1) {
                continue;
            }

            $locale = $matches[1];
            $catalog = TranslationCatalog::loadFile($file);
            $coverage[$locale] = $catalog === null
                ? LocaleTranslationCoverage::unknown($locale)
                : LocaleTranslationCoverage::compute($locale, $reference, $catalog);
        }

        ksort($coverage);

        return $coverage;
    }

    private function referenceCatalogPath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'translations'.\DIRECTORY_SEPARATOR.'messages.en.yaml';
    }
}
