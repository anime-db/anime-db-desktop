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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use App\Entity\ValueObject\PluginId;

/**
 * Host implementation of {@see SettingsStoreInterface} (contracts v0.8.0, issue #316): a plugin's
 * own settings — configuration values as well as OAuth tokens/secrets — live in a dedicated
 * `settings` subsection of that plugin's own entry in plugins.json, kept apart from the host's
 * `enabled`/`features` flags in the very same entry (see {@see WidgetActiveTrait},
 * {@see FillerActiveTrait}), so a plugin can never shadow a host flag by writing a key of its own
 * with the same name.
 *
 * Plaintext, same as the rest of plugins.json (issue #219): protection is filesystem ACLs on
 * AppData, not encryption.
 *
 * An instance is scoped to a single plugin — see
 * {@see DependencyInjection\Compiler\SettingsStoreScopePass}, which constructs one per installed
 * plugin the same way {@see DependencyInjection\Compiler\PluginDataStoreScopePass} does for
 * {@see PluginDataStore} — so neither method takes a {@see PluginId}: the instance already knows
 * its own, and a plugin can never reach another plugin's settings through this interface.
 */
final class SettingsStore implements SettingsStoreInterface
{
    public function __construct(
        private readonly PluginId $pluginId,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    public function read(): array
    {
        return $this->pluginsConfigStore->getSettingsStorePayload($this->pluginId);
    }

    public function write(array $settings): void
    {
        $this->pluginsConfigStore->writeSettingsStorePayload($this->pluginId, $settings);
    }
}
