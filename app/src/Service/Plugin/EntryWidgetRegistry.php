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

use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves the {@see EntryWidgetInterface} instance a single `/plugin/{pluginId}/widget/{widgetName}`
 * request (issue #212) is for, and lists the active ones so the anime detail page can render one
 * HTMX placeholder per widget.
 *
 * A plugin may declare several entry widgets (one class per widget, see the interface's PHPDoc),
 * unlike {@see FillerInterface} where the registry only ever needs one instance per plugin. The
 * service id therefore doubles as a compound "{pluginId}:{widgetName}" key rather than plain
 * PluginId, and — same reasoning as FillerRegistry — is expected to be assigned by the future
 * plugin manager (issues #218/#220-224, not implemented yet). Until then this iterable is simply
 * empty in production.
 *
 * Each widget is toggled independently: `isActive()` reads `features.{$widgetName}` from
 * plugins.json, not a single shared "widget" flag, so disabling one widget of a plugin never
 * touches its other widgets or its Filler/Sync features.
 */
final class EntryWidgetRegistry
{
    use WidgetActiveTrait;

    /** See {@see WidgetActiveTrait::changeActive()}, which reads this via `static::` — {@see CatalogWidgetRegistry::HARD_LIMIT} overrides it for the catalog placement (issue #728). */
    public const int HARD_LIMIT = 5;

    /**
     * Soft recommendation shown to the user as a performance/clutter hint once exceeded; never
     * blocks enabling a widget. Issue #742: {@see CatalogWidgetRegistry} declares no equivalent
     * constant — its `HARD_LIMIT` (2) already equals this value, so a recommendation there could
     * never be exceeded and would be dead UI.
     */
    public const int RECOMMENDED_LIMIT = 2;

    /**
     * Where on the anime detail page a widget is shown (issue #917): full width after the gallery
     * or in the right column after the "Files" block. Not to be confused with the page-level
     * placement (entry/catalog). Stored as `widget_slot.{widgetName}` in plugins.json and kept
     * when the widget is turned off.
     */
    public const string SLOT_BOTTOM = 'bottom';
    public const string SLOT_SIDE = 'side';
    public const array SLOTS = [self::SLOT_BOTTOM, self::SLOT_SIDE];

    /** @param iterable<string, EntryWidgetInterface> $widgets keyed by "{pluginId}:{widgetName}" */
    public function __construct(
        #[AutowireIterator('app.entry_widget', indexAttribute: 'id')]
        private readonly iterable $widgets,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly TranslatorInterface $translator,
        /**
         * Resolves the plugin's manifest `name` for {@see self::findAllActive()}'s slot header
         * (issue #728). Nullable with a `null` default, same convention as
         * {@see InstalledPluginsRegistry}'s own `$overlayWriter` param, so every existing direct
         * instantiation of this class in tests that predate issue #728 keeps compiling without
         * wiring up a dependency they do not care about — {@see self::pluginName()} simply falls
         * back to the raw plugin id in that case.
         */
        private readonly ?InstalledPluginsRegistry $installedPlugins = null,
    ) {
    }

    public function find(PluginId $pluginId, string $widgetName): ?EntryWidgetInterface
    {
        $widget = $this->all()[self::key($pluginId, $widgetName)] ?? null;

        return $widget !== null && $this->isActive($pluginId, $widgetName) ? $widget : null;
    }

    /**
     * `title`/`pluginName` are resolved the same way as {@see self::listAll()} (reusing
     * {@see self::translate()}, plus the manifest lookup in {@see self::pluginName()}) — the
     * host's slot header (issue #728) needs both to tell the widget's data apart from the user's
     * own catalog.
     *
     * @return list<array{pluginId: string, widgetName: string, title: string, pluginName: string, slot: string}> active
     *                                                                                                            widget slots to render, one HTMX placeholder per entry
     */
    public function findAllActive(): array
    {
        $result = [];
        foreach ($this->all() as $key => $widget) {
            [$pluginId, $widgetName] = explode(':', $key, 2);
            if (!$this->isActive(new PluginId($pluginId), $widgetName)) {
                continue;
            }

            $result[] = [
                'pluginId' => $pluginId,
                'widgetName' => $widgetName,
                'title' => $this->translate($widget::metadata()->titleKey, $pluginId, $widgetName),
                'pluginName' => $this->pluginName($pluginId),
                'slot' => $this->slot(new PluginId($pluginId), $widgetName),
            ];
        }

        return $result;
    }

    /**
     * `metadata()` (issue #364) gives `titleKey`/`descriptionKey`, not ready-to-display strings
     * (contract v0.14, issue #377): they are resolved here, in the plugin's translation domain
     * (= pluginId, see {@see PluginLoader}), read fresh on every call rather than cached — the
     * widget instances are already resolved once at container build time, so this is not a
     * repeated I/O cost, just a couple of property reads plus a translation lookup.
     *
     * A key without a matching catalog entry falls back to `widgetName` — Symfony's
     * `trans()` returns the key itself when unresolved, and a raw dotted key is not fit for
     * display. This also protects against the plugin shipping its key before the host does
     * (issue #377's rollout note: host ships first, plugins follow).
     *
     * @return list<array{pluginId: string, widgetName: string, active: bool, slot: string, title: string, description: string}> every
     *                                                                                                                           registered widget, active or not, for the settings UI (issue #213/#364)
     */
    public function listAll(): array
    {
        $result = [];
        foreach ($this->all() as $key => $widget) {
            [$pluginId, $widgetName] = explode(':', $key, 2);
            $metadata = $widget::metadata();
            $result[] = [
                'pluginId' => $pluginId,
                'widgetName' => $widgetName,
                'active' => $this->isActive(new PluginId($pluginId), $widgetName),
                'slot' => $this->slot(new PluginId($pluginId), $widgetName),
                'title' => $this->translate($metadata->titleKey, $pluginId, $widgetName),
                'description' => $this->translate($metadata->descriptionKey, $pluginId, $widgetName),
            ];
        }

        return $result;
    }

    /**
     * Enables or disables a single entry widget, enforcing the placement's hard limit of
     * simultaneously active widgets (issue #213).
     *
     * @throws Exception\WidgetHardLimitExceededException
     * @throws Exception\PluginsConfigStoreLockedException
     */
    public function setActive(PluginId $pluginId, string $widgetName, bool $active): void
    {
        $this->changeActive($pluginId, $widgetName, $active, \count($this->findAllActive()));
    }

    /**
     * Persists the slot of a widget regardless of its active state, so the choice survives
     * turning the widget off and on again.
     *
     * @throws \InvalidArgumentException                   for an unknown slot
     * @throws Exception\PluginsConfigStoreLockedException
     */
    public function setSlot(PluginId $pluginId, string $widgetName, string $slot): void
    {
        if (!\in_array($slot, self::SLOTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown widget slot "%s".', $slot));
        }

        $this->pluginsConfigStore->updatePluginSettings($pluginId, static function (array $settings) use ($widgetName, $slot): array {
            $settings['widget_slot'][$widgetName] = $slot;

            return $settings;
        });
    }

    /** A missing or unknown stored value means {@see self::SLOT_BOTTOM}. */
    private function slot(PluginId $pluginId, string $widgetName): string
    {
        $slots = $this->pluginsConfigStore->getPluginSettings($pluginId)['widget_slot'] ?? [];
        $slot = \is_array($slots) ? ($slots[$widgetName] ?? null) : null;

        return \in_array($slot, self::SLOTS, true) ? $slot : self::SLOT_BOTTOM;
    }

    /** @return array<string, EntryWidgetInterface> */
    private function all(): array
    {
        return iterator_to_array($this->widgets);
    }

    private function translate(string $key, string $pluginId, string $widgetName): string
    {
        $translated = $this->translator->trans($key, domain: $pluginId);

        return $translated !== $key ? $translated : $widgetName;
    }

    /** Falls back to the raw plugin id when {@see self::$installedPlugins} is unset or does not know this plugin — same fallback convention as {@see self::translate()}. */
    private function pluginName(string $pluginId): string
    {
        return $this->installedPlugins?->get(new PluginId($pluginId))?->manifest->name ?? $pluginId;
    }

    private static function key(PluginId $pluginId, string $widgetName): string
    {
        return $pluginId.':'.$widgetName;
    }
}
