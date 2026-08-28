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

use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\NearestBuiltInLocale;
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
 * {@see PluginType::Integration}/{@see PluginType::Local} plugins carry their own catalog too, but
 * in their own `<plugin-id>.<locale>.yaml` domain (issue #540) rather than the app's `messages`
 * one — there is nothing of the app's to compare their keys against, so for those types this
 * service only reports which locales the plugin ships, never a covered/missing key count. See
 * {@see PluginTranslationReport}.
 *
 * Console-only by design: reading and YAML-parsing a plugin's catalog on every request is exactly
 * what issue #84 ruled out for the request path (see {@see \App\Service\Plugin\AvailableLocalesProvider}
 * for the precedent). This service is wired into {@see \App\Command\TranslationsCoverageCommand}
 * and a settings-page action — never into {@see \App\EventSubscriber\LocaleSubscriber} or anything
 * else on the main request path.
 */
final class TranslationCoverageService
{
    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly string $projectDir,
        private readonly NearestBuiltInLocale $nearestBuiltInLocale = new NearestBuiltInLocale(),
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
     * This backfill only applies to {@see PluginType::Translation}: an Integration/Local plugin's
     * manifest `locales` describes its own domain, which this service does not read a reference
     * catalog for, so treating an undiscovered declared locale as "unknown" there would print a
     * meaningless `unknown` entry rather than the plain locale list the report is supposed to be.
     *
     * @return PluginTranslationReport|null null when no plugin with this id is installed
     */
    public function coverageForInstalledPlugin(PluginId $id): ?PluginTranslationReport
    {
        $plugin = $this->registry->get($id);
        if ($plugin === null) {
            return null;
        }

        $report = $this->coverageForPluginDirectory($plugin->installPath, $plugin->manifest->type, $plugin->manifest->id);

        if ($plugin->manifest->type !== PluginType::Translation) {
            return $report;
        }

        $coverage = $report->coverage;
        foreach ($plugin->manifest->locales ?? [] as $locale) {
            if (!isset($coverage[$locale])) {
                $coverage[$locale] = LocaleTranslationCoverage::unknown($locale);
            }
        }

        ksort($coverage);

        return PluginTranslationReport::translation($coverage);
    }

    /**
     * Reads $pluginDir straight off disk rather than through {@see InstalledPluginsRegistry} —
     * $pluginDir need not be among the installed plugins at all, which is the point: it lets a
     * plugin developer check a checkout that lives next to this app's own before ever packaging
     * or installing it.
     *
     * $pluginId is only required (and only used) for $type other than {@see PluginType::Translation}
     * — the domain an Integration/Local plugin's catalog files live under is `<plugin-id>`, not the
     * fixed `messages` domain, so building the right glob pattern needs it.
     *
     * @throws \InvalidArgumentException if $type is not Translation and $pluginId is not given
     */
    public function coverageForPluginDirectory(string $pluginDir, PluginType $type = PluginType::Translation, ?string $pluginId = null): PluginTranslationReport
    {
        if ($type !== PluginType::Translation) {
            return PluginTranslationReport::featureLocales($type, $this->localesForFeaturePlugin($pluginDir, $pluginId));
        }

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

        return PluginTranslationReport::translation($coverage);
    }

    /**
     * True when neither $currentLocale nor the built-in locale it falls back to (issue #538's
     * {@see NearestBuiltInLocale::fallbackChain()}) is among $report's locales — the signal that a
     * user viewing the interface in $currentLocale will see this plugin's raw translation keys
     * instead of any of its shipped catalogs.
     *
     * Always false for a {@see PluginType::Translation} report: its catalog lives in the app's own
     * `messages` domain, which always has an `en`/`ru` catalog of its own for the Translator to
     * fall back to, so a missing plugin locale there never surfaces raw keys the way a missing
     * Integration/Local locale does in its own, otherwise-empty domain.
     */
    public function isMissingFallbackLocale(PluginTranslationReport $report, ?string $currentLocale): bool
    {
        if ($report->type === PluginType::Translation) {
            return false;
        }

        $candidates = $this->nearestBuiltInLocale->fallbackChain($currentLocale);
        if ($currentLocale !== null && $currentLocale !== '') {
            $candidates[] = $currentLocale;
        }

        return array_intersect($candidates, $report->locales) === [];
    }

    /**
     * @return list<string> sorted locales found among $pluginDir/translations/$pluginId.<locale>.yaml
     */
    private function localesForFeaturePlugin(string $pluginDir, ?string $pluginId): array
    {
        if ($pluginId === null || $pluginId === '') {
            throw new \InvalidArgumentException('A plugin id is required to look up an Integration/Local plugin\'s translation catalogs.');
        }

        $translationsDir = $pluginDir.\DIRECTORY_SEPARATOR.'translations';
        $pattern = '/^'.preg_quote($pluginId, '/').'\.([a-zA-Z_-]+)\.yaml$/';

        $locales = [];
        foreach (glob($translationsDir.'/'.$pluginId.'.*.yaml') ?: [] as $file) {
            if (preg_match($pattern, basename($file), $matches) === 1) {
                $locales[] = $matches[1];
            }
        }

        sort($locales);

        return array_values(array_unique($locales));
    }

    /**
     * The app's own reference key count for the `messages` domain (issue #514), reusing the exact
     * same reference catalog {@see coverageForPluginDirectory()} compares a plugin against — the
     * market storefront's coverage badge divides a plugin's own key count (carried in its market
     * snapshot entry) by this number rather than recomputing it another way.
     */
    public function referenceKeyCount(): int
    {
        return \count(TranslationCatalog::loadFile($this->referenceCatalogPath()) ?? []);
    }

    private function referenceCatalogPath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'translations'.\DIRECTORY_SEPARATOR.'messages.en.yaml';
    }
}
