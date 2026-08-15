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

use AnimeDb\PluginContracts\Manifest\ManifestValidationError;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginSyntaxErrorException;
use App\Service\Plugin\InstalledPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginSyntaxError;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\WsPublisher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
 * Entry point for the custom ZIP install flow (issue #251): lists installed plugins
 * ({@see InstalledPluginsRegistry::all()}) and drives an uploaded ZIP through
 * {@see ZipPluginInstaller::install()}. The "third-party source" warning gate the archive must
 * pass through before the upload is submitted lives entirely client-side in
 * `settings/plugins/index.html.twig` / `js/plugin-install.js` — this controller only ever sees
 * an already-confirmed submission, the same way the server has no notion of the storage scan
 * confirm step's own UI state.
 *
 * A failed install re-renders this page directly (200, same as {@see \App\Controller\StorageNewController}'s
 * validation errors) instead of redirecting, so the error detail (which core version is
 * required, which file failed to lint, ...) does not need to survive a redirect via the query
 * string. A successful install does redirect (POST-Redirect-GET), so refreshing the result page
 * never resubmits the upload.
 *
 * Also drives plugin removal (issue #225) via {@see remove()}: deletes the plugin's directory and
 * re-syncs {@see InstalledPluginsRegistry} through {@see PluginRemover} — deliberately the only
 * thing it touches. A plugin's accumulated catalog data (`anime_plugin_data`, `anime_external_id`)
 * is never cleared, on either the entity or the removal side: those rows have no foreign key to
 * the plugin and are meant to outlive an uninstall, so a later reinstall of the same plugin id
 * re-links to what it already knew instead of starting over. On success, publishes
 * {@see ZipPluginInstaller::WORKERS_RELOAD_EVENT} the same way {@see ZipPluginInstaller::install()}
 * does, so a live FrankenPHP worker (which keeps the removed plugin's classes in its already
 * compiled container until restarted) and the messenger consumer both pick up the removal instead
 * of continuing to reference a now-deleted plugin directory.
 */
final class PluginController
{
    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly SettingsPageRegistry $settingsPages,
        private readonly ZipPluginInstaller $installer,
        private readonly PluginRemover $remover,
        private readonly WsPublisher $wsPublisher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/settings/plugins', name: 'settings_plugins_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $installedPluginId = (string) $request->query->get('installed', '');
        $removedPluginId = (string) $request->query->get('removed', '');

        return $this->renderIndex(
            installedPluginId: $installedPluginId !== '' ? $installedPluginId : null,
            removedPluginId: $removedPluginId !== '' ? $removedPluginId : null,
        );
    }

    #[Route('/settings/plugins/{pluginId}/remove', name: 'settings_plugins_remove', methods: ['POST'])]
    public function remove(string $pluginId, Request $request): Response
    {
        $this->assertValidCsrfToken('settings_plugins_remove_'.$pluginId, $request);

        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $this->remover->remove($id);

        try {
            $this->wsPublisher->publish(ZipPluginInstaller::WORKERS_RELOAD_EVENT, ['pluginId' => (string) $id]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to publish {event} for plugin {pluginId}: {message}', [
                'event' => ZipPluginInstaller::WORKERS_RELOAD_EVENT,
                'pluginId' => (string) $id,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_plugins_index', ['removed' => (string) $id]));
    }

    #[Route('/settings/plugins/install', name: 'settings_plugins_install', methods: ['POST'])]
    public function install(Request $request): Response
    {
        $this->assertValidCsrfToken('settings_plugins_install', $request);

        $file = $request->files->get('plugin_zip');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->renderIndex(installError: 'settings_plugins.install_error_no_file');
        }

        try {
            $pluginId = $this->installer->install($file->getPathname());
        } catch (IncompatiblePluginCoreVersionException $exception) {
            return $this->renderIndex(
                installError: 'settings_plugins.install_error_incompatible_core',
                installErrorParams: [
                    '%requiredCore%' => $exception->requiredCore,
                    '%currentCore%' => $exception->currentCore,
                ],
            );
        } catch (PluginSyntaxErrorException $exception) {
            return $this->renderIndex(
                installError: 'settings_plugins.install_error_syntax',
                syntaxErrors: $exception->errors,
            );
        } catch (PluginAlreadyInstalledException $exception) {
            return $this->renderIndex(
                installError: 'settings_plugins.install_error_already_installed',
                installErrorParams: ['%pluginId%' => (string) $exception->pluginId],
            );
        } catch (InvalidInstalledPluginException $exception) {
            return $this->renderIndex(
                installError: 'settings_plugins.install_error_invalid_manifest',
                manifestErrors: $exception->errors,
            );
        } catch (PluginInstallException) {
            return $this->renderIndex(installError: 'settings_plugins.install_error_generic');
        } finally {
            @unlink($file->getPathname());
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_plugins_index', ['installed' => (string) $pluginId]));
    }

    /**
     * @param array<string, string>     $installErrorParams
     * @param PluginSyntaxError[]       $syntaxErrors
     * @param ManifestValidationError[] $manifestErrors
     */
    private function renderIndex(
        ?string $installedPluginId = null,
        ?string $removedPluginId = null,
        ?string $installError = null,
        array $installErrorParams = [],
        array $syntaxErrors = [],
        array $manifestErrors = [],
    ): Response {
        $installedPlugins = $this->installedPlugins->all();

        return new Response($this->twig->render('settings/plugins/index.html.twig', [
            'installedPlugins' => $installedPlugins,
            'settingsPluginIds' => $this->pluginIdsWithASettingsPage($installedPlugins),
            'installedPluginId' => $installedPluginId,
            'removedPluginId' => $removedPluginId,
            'installError' => $installError,
            'installErrorParams' => $installErrorParams,
            'syntaxErrors' => $syntaxErrors,
            'manifestErrors' => $manifestErrors,
        ]));
    }

    /**
     * @param list<InstalledPlugin> $installedPlugins
     *
     * @return list<string> ids of plugins with a settings page the user may currently open —
     *                      {@see SettingsPageRegistry::find()} already folds in the `enabled` gate
     */
    private function pluginIdsWithASettingsPage(array $installedPlugins): array
    {
        $ids = [];
        foreach ($installedPlugins as $plugin) {
            if ($this->settingsPages->find($plugin->id) !== null) {
                $ids[] = (string) $plugin->id;
            }
        }

        return $ids;
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
