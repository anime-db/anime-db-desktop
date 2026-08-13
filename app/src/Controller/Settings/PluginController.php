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
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginSyntaxErrorException;
use App\Service\Plugin\InstalledPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginSyntaxError;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\ZipPluginInstaller;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
 */
final class PluginController
{
    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly SettingsPageRegistry $settingsPages,
        private readonly ZipPluginInstaller $installer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/plugins', name: 'settings_plugins_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $installedPluginId = (string) $request->query->get('installed', '');

        return $this->renderIndex(installedPluginId: $installedPluginId !== '' ? $installedPluginId : null);
    }

    #[Route('/settings/plugins/install', name: 'settings_plugins_install', methods: ['POST'])]
    public function install(Request $request): Response
    {
        $this->assertValidCsrfToken($request);

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

    private function assertValidCsrfToken(Request $request): void
    {
        $token = new CsrfToken('settings_plugins_install', (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
