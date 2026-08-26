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
use AnimeDb\PluginContracts\Manifest\PluginType;
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
use App\Service\Translation\TranslationCoverageService;
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
 * Also drives updating an already-installed plugin (issue #224): re-submitting the same install
 * form with a ZIP whose manifest id is already installed no longer surfaces
 * {@see PluginAlreadyInstalledException} as a blocking error — {@see install()} instead retries
 * through {@see ZipPluginInstaller::update()} via {@see installOrUpdate()}, since uploading a new
 * archive through the third-party-warning-gated form is itself the explicit user action that
 * authorizes it. The redirect after a successful update carries an `updated` query parameter
 * instead of `installed` so the flash message on {@see index()} reads correctly.
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
        private readonly TranslationCoverageService $translationCoverage,
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
        $updatedPluginId = (string) $request->query->get('updated', '');
        $removedPluginId = (string) $request->query->get('removed', '');

        return $this->renderIndex(
            installedPluginId: $installedPluginId !== '' ? $installedPluginId : null,
            updatedPluginId: $updatedPluginId !== '' ? $updatedPluginId : null,
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
            [$pluginId, $updated] = $this->installOrUpdate($file->getPathname());
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

        return new RedirectResponse($this->urlGenerator->generate(
            'settings_plugins_index',
            $updated ? ['updated' => (string) $pluginId] : ['installed' => (string) $pluginId],
        ));
    }

    /**
     * Installs the uploaded archive as a new plugin, or — if its manifest id is already installed
     * — updates it in place instead of surfacing {@see PluginAlreadyInstalledException} as a
     * blocking error (issue #224): re-submitting the install form with a newer ZIP of an already
     * installed plugin is the explicit user action that authorizes the update, the same way it
     * authorizes a first-time install.
     *
     * @return array{0: PluginId, 1: bool} the resulting plugin id, and whether this was an update
     *                                     of an already-installed plugin rather than a fresh install
     */
    private function installOrUpdate(string $zipPath): array
    {
        try {
            return [$this->installer->install($zipPath), false];
        } catch (PluginAlreadyInstalledException) {
            return [$this->installer->update($zipPath), true];
        }
    }

    /**
     * @param array<string, string>     $installErrorParams
     * @param PluginSyntaxError[]       $syntaxErrors
     * @param ManifestValidationError[] $manifestErrors
     */
    private function renderIndex(
        ?string $installedPluginId = null,
        ?string $updatedPluginId = null,
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
            'translationCoverage' => $this->translationCoverageByPlugin($installedPlugins),
            'installedPluginId' => $installedPluginId,
            'updatedPluginId' => $updatedPluginId,
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

    /**
     * Renders issue #513's per-plugin translation-coverage badges from {@see TranslationCoverageService}
     * (issue #512) — computed once here, at page render, never per-request or from a locale
     * subscriber (the service's own docblock rules that out). Only {@see PluginType::Translation}
     * plugins are covered against the app's `messages` domain: an `integration` plugin's strings
     * live in its own `<plugin-id>.<locale>.yaml` domain, so a coverage number here would be
     * meaningless for it. A locale the manifest declares but with no catalog file on disk
     * ({@see \App\Service\Translation\LocaleTranslationCoverage::isKnown} false) has no covered/total
     * to show, so it is left out of the badge list rather than shown as a misleading "0 of 0".
     *
     * @param list<InstalledPlugin> $installedPlugins
     *
     * @return array<string, array<string, array{covered: int, total: int}>> keyed by plugin id,
     *                                                                       then by locale
     */
    private function translationCoverageByPlugin(array $installedPlugins): array
    {
        $coverageByPlugin = [];
        foreach ($installedPlugins as $plugin) {
            if ($plugin->manifest->type !== PluginType::Translation) {
                continue;
            }

            $localeCoverage = [];
            foreach ($this->translationCoverage->coverageForInstalledPlugin($plugin->id) ?? [] as $locale => $coverage) {
                if (!$coverage->isKnown) {
                    continue;
                }

                $localeCoverage[$locale] = [
                    'covered' => $coverage->covered,
                    'total' => $coverage->covered + \count($coverage->missing),
                ];
            }

            if ($localeCoverage !== []) {
                $coverageByPlugin[(string) $plugin->id] = $localeCoverage;
            }
        }

        return $coverageByPlugin;
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
