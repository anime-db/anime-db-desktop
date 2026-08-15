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
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use App\Service\Market\MarketAssetDownloader;
use App\Service\Market\MarketPlugin;
use App\Service\Market\PluginRegistry;
use App\Service\Market\PluginRegistryLoader;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginNotInstalledException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\ZipPluginInstaller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The official market storefront (issue #220): lists plugins from the trusted, already
 * signature-verified `plugins-registry.json` ({@see PluginRegistryLoader}, issue #292) and drives
 * an "Install" click through the same {@see ZipPluginInstaller} the custom-ZIP path uses
 * ({@see PluginController}, issue #251) — download+verify (issue #292 §3,
 * {@see MarketAssetDownloader}), unpack/move/reconcile, isolated cache warm-up and live
 * activation (issue #222), all already implemented by that installer.
 *
 * Two things the custom-ZIP path shows are deliberately absent here: the `php -l` syntax lint
 * (skipped via {@see ZipPluginInstaller::install()}'s `$trusted` flag) and the "third-party
 * source" confirmation gate (that gate is client-side only in `settings/plugins/index.html.twig`
 * and simply is not rendered by this controller's template) — both exist only because a
 * custom-uploaded ZIP was never reviewed by anything; a market plugin already passed the
 * registry's own CI before it was ever listed here.
 *
 * Each plugin's row shows the manifest of its *latest* published version — the registry stores
 * only that one inline ({@see PluginRegistry::plugins()}) — but installs whichever version
 * {@see MarketPlugin::resolveCompatibleVersion()} actually resolves against the current
 * `%app.core_version%`, which may be an older one. A plugin with no compatible version at all is
 * rendered inactive with a "needs core version X" hint instead of an "Install" button.
 *
 * An already-installed plugin whose resolved compatible version differs from the one on disk gets
 * an "Update" button ({@see update()}, issue #224) instead of the plain "already installed" label
 * — driven through {@see ZipPluginInstaller::update()}, which runs the same download+SHA-256
 * verification and isolated warm-up as {@see install()} but swaps the new version into place
 * behind a backup of the old one, so a failed warm-up restores it instead of leaving the plugin
 * directory empty. There is deliberately no automatic update: the resolved version merely decides
 * whether the button is shown, the update itself always waits for this explicit click.
 */
final class MarketController
{
    public function __construct(
        private readonly PluginRegistryLoader $registryLoader,
        private readonly MarketAssetDownloader $assetDownloader,
        private readonly ZipPluginInstaller $installer,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly string $coreVersion,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/market', name: 'settings_market_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $installedPluginId = (string) $request->query->get('installed', '');
        $updatedPluginId = (string) $request->query->get('updated', '');

        return $this->renderIndex(
            installedPluginId: $installedPluginId !== '' ? $installedPluginId : null,
            updatedPluginId: $updatedPluginId !== '' ? $updatedPluginId : null,
        );
    }

    #[Route('/settings/market/{pluginId}/install', name: 'settings_market_install', methods: ['POST'])]
    public function install(string $pluginId, Request $request): Response
    {
        return $this->installOrUpdate($pluginId, $request, update: false);
    }

    /**
     * Updates an already-installed plugin to whichever version {@see MarketPlugin::resolveCompatibleVersion()}
     * currently resolves against `%app.core_version%` (issue #224) — only reachable by an explicit
     * click of the "Update" button {@see renderIndex()} renders for such a plugin; there is no
     * silent auto-update.
     */
    #[Route('/settings/market/{pluginId}/update', name: 'settings_market_update', methods: ['POST'])]
    public function update(string $pluginId, Request $request): Response
    {
        return $this->installOrUpdate($pluginId, $request, update: true);
    }

    private function installOrUpdate(string $pluginId, Request $request, bool $update): Response
    {
        $this->assertValidCsrfToken(($update ? 'settings_market_update_' : 'settings_market_install_').$pluginId, $request);

        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $result = $this->registryLoader->load();
        $registry = $result->registry;
        if ($registry === null) {
            return $this->renderIndex(installError: 'settings_market.install_error_registry_unavailable');
        }

        $plugin = $this->findPlugin($registry, $id);
        if ($plugin === null) {
            return $this->renderIndex(installError: 'settings_market.install_error_unknown_plugin');
        }

        $version = $plugin->resolveCompatibleVersion($this->coreVersion);
        if ($version === null) {
            return $this->renderIndex(
                installError: 'settings_market.install_error_incompatible_core',
                installErrorParams: ['%requiredCore%' => $plugin->latestVersion()->core, '%currentCore%' => $this->coreVersion],
            );
        }

        try {
            $zipPath = $this->assetDownloader->downloadPluginZip($registry, $id, $version->version);
        } catch (UnknownPluginVersionException|PluginAssetDownloadException) {
            return $this->renderIndex(installError: 'settings_market.install_error_download_failed');
        }

        try {
            if ($update) {
                $this->installer->update($zipPath, trusted: true);
            } else {
                $this->installer->install($zipPath, trusted: true);
            }
        } catch (IncompatiblePluginCoreVersionException $exception) {
            return $this->renderIndex(
                installError: 'settings_market.install_error_incompatible_core',
                installErrorParams: ['%requiredCore%' => $exception->requiredCore, '%currentCore%' => $exception->currentCore],
            );
        } catch (PluginAlreadyInstalledException $exception) {
            return $this->renderIndex(
                installError: 'settings_market.install_error_already_installed',
                installErrorParams: ['%pluginId%' => (string) $exception->pluginId],
            );
        } catch (PluginNotInstalledException) {
            // The plugin was removed by another request between rendering the "Update" button and
            // this click — not installing it here would silently second-guess that removal.
            return $this->renderIndex(installError: 'settings_market.install_error_generic');
        } catch (InvalidInstalledPluginException) {
            return $this->renderIndex(installError: 'settings_market.install_error_invalid_manifest');
        } catch (PluginInstallException) {
            return $this->renderIndex(installError: 'settings_market.install_error_generic');
        } finally {
            @unlink($zipPath);
        }

        return new RedirectResponse($this->urlGenerator->generate(
            'settings_market_index',
            $update ? ['updated' => (string) $id] : ['installed' => (string) $id],
        ));
    }

    /**
     * @param array<string, string> $installErrorParams
     */
    private function renderIndex(
        ?string $installedPluginId = null,
        ?string $updatedPluginId = null,
        ?string $installError = null,
        array $installErrorParams = [],
    ): Response {
        $result = $this->registryLoader->load();
        $registry = $result->registry;

        $items = [];
        foreach ($registry?->plugins() ?? [] as $plugin) {
            $installedPlugin = $this->installedPlugins->get($plugin->id);
            $resolvedVersion = $plugin->resolveCompatibleVersion($this->coreVersion);

            $items[] = [
                'plugin' => $plugin,
                'resolvedVersion' => $resolvedVersion,
                'installed' => $installedPlugin !== null,
                'updateAvailable' => $installedPlugin !== null
                    && $resolvedVersion !== null
                    && $resolvedVersion->version !== $installedPlugin->manifest->version,
            ];
        }

        return new Response($this->twig->render('settings/market/index.html.twig', [
            'items' => $items,
            'registryUnavailable' => $registry === null,
            'installedPluginId' => $installedPluginId,
            'updatedPluginId' => $updatedPluginId,
            'installError' => $installError,
            'installErrorParams' => $installErrorParams,
        ]));
    }

    private function findPlugin(PluginRegistry $registry, PluginId $id): ?MarketPlugin
    {
        foreach ($registry->plugins() as $plugin) {
            if ((string) $plugin->id === (string) $id) {
                return $plugin;
            }
        }

        return null;
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
