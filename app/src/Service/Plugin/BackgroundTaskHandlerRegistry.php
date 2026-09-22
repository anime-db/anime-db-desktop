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

use AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see BackgroundTaskHandlerInterface} a queued {@see \App\Message\RunPluginBackgroundTaskMessage}
 * belongs to (issue #702, part 2 of 3 for #684), for
 * {@see \App\MessageHandler\RunPluginBackgroundTaskMessageHandler} to call `handle()` on in the
 * background process.
 *
 * `BackgroundTaskHandlerInterface` lives in the read-only `anime-db/plugin-contracts` package and
 * cannot carry `#[AutoconfigureTag]` itself, so it is tagged 'app.background_task_handler' at
 * compile time by {@see DependencyInjection\Compiler\TagPluginServicesPass} — same mechanism as
 * `app.filler`/`app.sync`/`app.settings_page`. A plugin has at most one handler (the compiler pass
 * rejects a second one under the same plugin id outright), so the injected iterable is keyed by
 * plain {@see PluginId}, the same shape as {@see SettingsPageRegistry}.
 *
 * {@see self::find()} returns `null` both when no handler is registered under the given plugin id
 * (the plugin was removed while its task sat queued) and when the plugin is installed but
 * disabled — the handler-lookup caller logs and drops the message either way, never throws.
 */
final class BackgroundTaskHandlerRegistry
{
    /** @param iterable<string, BackgroundTaskHandlerInterface> $handlers keyed by plugin id */
    public function __construct(
        #[AutowireIterator('app.background_task_handler', indexAttribute: 'id')]
        private readonly iterable $handlers,
        private readonly InstalledPluginsRegistry $installedPlugins,
    ) {
    }

    public function find(PluginId $pluginId): ?BackgroundTaskHandlerInterface
    {
        $plugin = $this->installedPlugins->get($pluginId);
        if ($plugin === null || !$plugin->enabled) {
            return null;
        }

        return iterator_to_array($this->handlers)[(string) $pluginId] ?? null;
    }
}
