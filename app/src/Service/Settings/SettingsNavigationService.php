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

namespace App\Service\Settings;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Sync\SyncReviewService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the settings sidebar (issue #822): one page with a sidebar of groups replaces the old
 * three-places-at-once navigation (top menu, a flat link list on `/settings`, per-page "Settings"
 * back buttons). Consumed from Twig via {@see \App\Twig\SettingsNavigationExtension} rather than
 * threaded through every controller's render() call, so a controller never needs to know the
 * sidebar exists.
 *
 * The active item is resolved from an explicit route-name table ({@see self::ROUTE_ITEM_MAP}),
 * not a route-name prefix: `settings_plugins_*` covers both the installed-plugins list and the
 * ZIP-install page, and `settings_plugin_*` covers both a plugin's own settings page and the
 * widgets page — a prefix cannot tell those apart. The table also carries POST routes that render
 * a page directly instead of redirecting (a validation error, an install failure, ...), since
 * those never reach the sidebar through any other path. A route absent from the table (e.g. one a
 * plugin declares itself) simply renders with no highlighted item — see
 * `SettingsNavigationRouteMapTest` for the routes this table is required to cover.
 *
 * The "Plugin settings" group lists one item per *installed and enabled* plugin that has a
 * settings page, resolved via {@see SettingsPageRegistry::enabledPluginIdsWithSettingsPage()} —
 * deliberately the locator-backed method, not {@see SettingsPageRegistry::find()}: this group is
 * built on every settings page load, so it must never instantiate a plugin's settings-page service
 * just to list it. Building the group is wrapped in a try/catch of its own: a failure anywhere in
 * it (a corrupted plugin entry, ...) drops the whole group and logs, rather than taking down every
 * settings page — most importantly `/settings/backup`, which is where a user recovers from exactly
 * that kind of corruption. The "needs correction" badge on the sync-review item gets the same
 * treatment for the same reason: it is now computed on every settings page load, not just on the
 * page it counts for.
 */
final class SettingsNavigationService
{
    /**
     * Route name => stable item id. {@see self::activeItemId()} falls back to a plugin-specific id
     * for `settings_plugin_page`, which cannot be a static entry here (it depends on `{pluginId}`).
     *
     * @var array<string, string>
     */
    public const array ROUTE_ITEM_MAP = [
        'settings_index' => 'interface',
        'storage_index' => 'storage',
        'storage_new' => 'storage',
        'storage_create' => 'storage',
        'storage_edit' => 'storage',
        'storage_update' => 'storage',
        'settings_labels_index' => 'labels',
        'settings_sync_review_index' => 'sync_review',
        'settings_backup_index' => 'backup',
        'settings_search_index' => 'search_index',
        'settings_market_index' => 'market',
        'settings_market_install' => 'market',
        'settings_market_update' => 'market',
        'settings_plugins_index' => 'plugins',
        'settings_plugins_install_index' => 'plugins_install',
        'settings_plugins_install' => 'plugins_install',
        'settings_plugin_widgets_index' => 'plugin_widgets',
        'settings_proxy_index' => 'proxy',
        'settings_proxy_save' => 'proxy',
        'settings_proxy_incoming_connections' => 'proxy',
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly SettingsPageRegistry $settingsPages,
        private readonly SyncReviewService $syncReview,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<SettingsNavGroup> */
    public function groups(): array
    {
        $active = $this->activeItemId();

        $groups = [
            new SettingsNavGroup($this->trans('group_general'), [
                $this->item('interface', 'item_interface', 'settings_index', $active),
            ]),
            new SettingsNavGroup($this->trans('group_catalog'), [
                $this->item('storage', 'item_storage', 'storage_index', $active),
                $this->item('labels', 'item_labels', 'settings_labels_index', $active),
                $this->item('sync_review', 'item_sync_review', 'settings_sync_review_index', $active, badge: $this->needsCorrectionBadge()),
                $this->item('backup', 'item_backup', 'settings_backup_index', $active),
                $this->item('search_index', 'item_search_index', 'settings_search_index', $active),
            ]),
            new SettingsNavGroup($this->trans('group_plugins'), [
                $this->item('market', 'item_market', 'settings_market_index', $active),
                $this->item('plugins', 'item_plugins_installed', 'settings_plugins_index', $active),
                $this->item('plugins_install', 'item_plugins_install', 'settings_plugins_install_index', $active),
                $this->item('plugin_widgets', 'item_plugin_widgets', 'settings_plugin_widgets_index', $active),
            ]),
        ];

        $pluginSettingsGroup = $this->buildPluginSettingsGroup($active);
        if ($pluginSettingsGroup !== null) {
            $groups[] = $pluginSettingsGroup;
        }

        $groups[] = new SettingsNavGroup($this->trans('group_network'), [
            $this->item('proxy', 'item_proxy', 'settings_proxy_index', $active),
        ]);

        return $groups;
    }

    private function item(string $id, string $labelKey, string $route, ?string $activeId, ?int $badge = null): SettingsNavItem
    {
        return new SettingsNavItem($id, $this->trans($labelKey), $this->urlGenerator->generate($route), $id === $activeId, $badge);
    }

    private function buildPluginSettingsGroup(?string $activeId): ?SettingsNavGroup
    {
        try {
            $pluginIds = $this->settingsPages->enabledPluginIdsWithSettingsPage();
            if ($pluginIds === []) {
                return null;
            }

            $items = [];
            foreach ($pluginIds as $pluginId) {
                $items[] = $this->pluginSettingsItem($pluginId, $activeId);
            }

            return new SettingsNavGroup($this->trans('group_plugin_settings'), $items);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to build the plugin settings sidebar group; omitting it.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * @throws InvalidPluginIdException
     */
    private function pluginSettingsItem(string $pluginId, ?string $activeId): SettingsNavItem
    {
        $id = 'plugin:'.$pluginId;
        $plugin = $this->installedPlugins->get(new PluginId($pluginId));
        $label = $plugin?->manifest->name ?? $pluginId;

        return new SettingsNavItem(
            $id,
            $label,
            $this->urlGenerator->generate('settings_plugin_page', ['pluginId' => $pluginId]),
            $id === $activeId,
        );
    }

    /**
     * Badge count for the sidebar's "Requires attention" item (issue #382, moved from the old
     * flat `/settings` link list by issue #822): unresolved `NeedsCorrection` items specifically,
     * not every `SyncReviewItem` kind — the one kind a user cannot otherwise notice until they open
     * the page. Fails open to no badge (rather than a 500 for every settings page) on any
     * repository failure, logged the same way as {@see self::buildPluginSettingsGroup()}.
     */
    private function needsCorrectionBadge(): ?int
    {
        try {
            $count = $this->syncReview->countUnresolvedNeedsCorrection();

            return $count > 0 ? $count : null;
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to compute the "needs correction" sidebar badge; omitting it.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function activeItemId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route)) {
            return null;
        }

        if ($route === 'settings_plugin_page') {
            $pluginId = $request->attributes->get('pluginId');

            return \is_string($pluginId) ? 'plugin:'.$pluginId : null;
        }

        return self::ROUTE_ITEM_MAP[$route] ?? null;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('settings_nav.'.$key);
    }
}
