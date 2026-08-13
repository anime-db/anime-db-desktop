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

namespace App\Controller\Settings;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\SettingsPageRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Embeds a plugin's own settings page (issue #317,
 * {@see \AnimeDb\PluginContracts\Settings\SettingsPageInterface::render()}) into the host's
 * settings shell — the Chrome `options_ui` model: the plugin owns the markup, the host only
 * supplies the frame around it. Access is gated by {@see SettingsPageRegistry}, which itself
 * gates on the *whole plugin's* `enabled` flag ({@see InstalledPluginsRegistry}), not a
 * `features.*` flag — this is where the user flips `enabled` and completes OAuth, so a
 * feature-flag gate would be a deadlock with no way out through the UI.
 *
 * `render()` runs in a try/catch, the same shape as {@see \App\Controller\PluginWidgetController}:
 * a broken settings page must not take down the settings area for every other plugin. Unlike the
 * widget slot, this is a full top-level page (not an HTMX fragment), so a failure degrades to an
 * inline error message on an otherwise normal 200 response rather than a swappable fragment.
 */
final class PluginSettingsController
{
    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly SettingsPageRegistry $settingsPages,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/settings/plugins/{pluginId}',
        name: 'settings_plugin_page',
        requirements: ['pluginId' => '[a-z0-9]+(-[a-z0-9]+)+'],
        methods: ['GET'],
    )]
    public function __invoke(string $pluginId): Response
    {
        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $page = $this->settingsPages->find($id);
        if ($page === null) {
            throw new NotFoundHttpException(\sprintf('Plugin "%s" has no settings page, or the plugin is disabled.', $pluginId));
        }

        // SettingsPageRegistry::find() only returns non-null for an installed plugin, so this is
        // never actually null — re-fetched only to read the plugin's display name for the shell.
        $plugin = $this->installedPlugins->get($id);
        if ($plugin === null) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        try {
            $content = $page->render();
        } catch (\Throwable $exception) {
            $this->logger->error('Plugin settings page render() failed.', [
                'pluginId' => $pluginId,
                'exception' => $exception,
            ]);

            return new Response($this->twig->render('settings/plugin/page.html.twig', [
                'pluginId' => $pluginId,
                'pluginName' => $plugin->manifest->name,
                'content' => null,
                'renderFailed' => true,
            ]));
        }

        return new Response($this->twig->render('settings/plugin/page.html.twig', [
            'pluginId' => $pluginId,
            'pluginName' => $plugin->manifest->name,
            'content' => $content,
            'renderFailed' => false,
        ]));
    }
}
