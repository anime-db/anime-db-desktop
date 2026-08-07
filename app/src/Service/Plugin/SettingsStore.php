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

use AnimeDb\PluginContracts\Settings\ConcurrentWriteException;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginsConfigStoreException;
use App\Service\Plugin\Exception\PluginsConfigStoreLockedException;

/**
 * Host implementation of {@see SettingsStoreInterface} (contracts v0.10.0, issues #316, #340): a
 * plugin's own settings — configuration values as well as OAuth tokens/secrets — live in a
 * dedicated `settings` subsection of that plugin's own entry in plugins.json, kept apart from the
 * host's `enabled`/`features` flags in the very same entry (see {@see WidgetActiveTrait},
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

    /**
     * Scopes $modifier to this plugin's `settings` subsection only, leaving its `enabled`/
     * `features` keys and every other plugin's entry untouched, then goes through
     * {@see PluginsConfigStore::updatePluginSettings()} for the atomic read -> modify -> write
     * cycle. {@see PluginsConfigStoreLockedException} — the host-internal "another writer holds
     * the lock" signal — is mapped onto the contract's {@see ConcurrentWriteException} here, at
     * the plugin boundary; a buggy $modifier that does not return an array surfaces as a plain
     * {@see PluginsConfigStoreException} instead, since that is not a "busy, retry" condition.
     *
     * $modifier is plugin-authored code this package cannot statically verify, so its return
     * type is deliberately documented as `mixed` here rather than trusting the interface's
     * `array<string, mixed>` phpdoc: a plugin that forgets its `return` statement must be caught
     * at runtime instead of silently wiping every setting it has stored (issue #340).
     *
     * @param callable(array<string, mixed>): mixed $modifier
     */
    public function update(callable $modifier): void
    {
        try {
            $this->pluginsConfigStore->updatePluginSettings(
                $this->pluginId,
                function (array $settings) use ($modifier): array {
                    $current = \is_array($settings['settings'] ?? null) ? $settings['settings'] : [];
                    $updated = $modifier($current);
                    if (!\is_array($updated)) {
                        throw new PluginsConfigStoreException(\sprintf('Settings modifier for plugin "%s" must return an array, got %s.', $this->pluginId, get_debug_type($updated)));
                    }
                    $settings['settings'] = $updated;

                    return $settings;
                },
            );
        } catch (PluginsConfigStoreLockedException $e) {
            throw new ConcurrentWriteException($e->getMessage(), previous: $e);
        }
    }
}
