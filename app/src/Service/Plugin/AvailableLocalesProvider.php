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
use App\Event\InstalledPluginsChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Extends the built-in locale set (`app.locales`) with locales declared by enabled
 * {@see PluginType::Translation} plugins (issue #453) — {@see PluginType::Integration} manifests
 * cannot declare `locales` at all (see `ManifestValidator`), so they never contribute here, only
 * to their own translation domain via {@see PluginLoader::translationPaths()}.
 *
 * The computed list is cached in memory for the lifetime of the worker process, not recomputed
 * on every request: {@see InstalledPluginsRegistry::enabled()} reads the plugin index (and, per
 * plugin, `plugins.json`) from disk, and issue #84 already established that this class of lookup
 * must not happen on every main request in FrankenPHP worker mode. The cache is invalidated by
 * {@see InstalledPluginsChangedEvent} — dispatched right when the installed/enabled plugin set
 * actually changes (install, uninstall, enable, disable) — so a subsequent request sees the
 * update without waiting for a worker restart, while requests in between pay no I/O at all.
 */
final class AvailableLocalesProvider implements EventSubscriberInterface
{
    /**
     * @var list<string>|null
     */
    private ?array $locales = null;

    /**
     * @param list<string> $coreLocales
     */
    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly array $coreLocales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InstalledPluginsChangedEvent::class => 'invalidate',
        ];
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->locales ??= $this->compute();
    }

    public function invalidate(): void
    {
        $this->locales = null;
    }

    /**
     * @return list<string>
     */
    private function compute(): array
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
