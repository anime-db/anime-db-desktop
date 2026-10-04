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
use App\Message\SyncSeedMessage;
use App\Service\Plugin\Exception\PluginsConfigStoreLockedException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginHtmlSanitizer;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PluginUiAssetsResolver;
use App\Service\Plugin\SettingsPageRegistry;
use App\Service\Plugin\SyncRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
 *
 * Connect-seed (issue #381): a plugin's own toggle/OAuth routes land the browser back on this GET
 * route as a full top-level navigation once the plugin implements
 * {@see \AnimeDb\PluginContracts\Sync\SyncInterface} and is active — {@see SyncRegistry} is the
 * single source of truth for that. This route is also the settings page itself, reachable again
 * on every later visit (including a browser prefetch or a plain reload), so the seed must not
 * fire more than once: a `syncSeeded` flag on the plugin's entry in {@see PluginsConfigStore},
 * checked and set atomically under its `updatePluginSettings()` lock, gates the dispatch. Once
 * seeded, this route falls through to rendering the plugin's own settings markup as normal — the
 * page stays reachable for re-authorizing an expired OAuth token or changing the plugin's own
 * settings. That first visit dispatches a one-time {@see SyncSeedMessage} (full pull, on the
 * `sync` transport so it never blocks this request) and renders the plugin's own settings markup
 * as normal, same as every later visit, rather than redirecting away to the sync review page
 * (issue #865): `features.sync` can be on before the plugin's own OAuth flow has completed, and a
 * redirect there would strand the user away from the plugin's settings markup — where its own
 * authorize button lives — on every visit, since a pull that stops short on missing OAuth resets
 * `syncSeeded` below and this branch runs again next time. Instead, a host notice rendered above
 * the plugin's markup (`settings/plugin/page.html.twig`'s `syncReviewUrl`) says the seed has
 * started and links to the sync review page, shown only for the one visit that just queued the
 * seed — {@see \App\Service\Plugin\PullSyncService::pull()} is what actually applies agreements to
 * local and raises review items for genuine conflicts. The external-id backfill (issue #258) is not
 * dispatched from here: {@see \App\MessageHandler\SyncSeedMessageHandler} runs it itself before the
 * pull (issue #867), so the order does not depend on message delivery.
 *
 * `SyncRegistry::isActive()` gates on `features.sync` alone, which the switch on the plugins
 * page ({@see PluginController::toggleSync()}) sets before the plugin's OAuth flow actually
 * completes — so setting `syncSeeded` here only makes the *dispatch* idempotent, it is not proof the pull that follows will actually run. If it stops
 * short on a dead/missing OAuth session, {@see \App\MessageHandler\SyncSeedMessageHandler} resets
 * `syncSeeded` back to `false` itself, so the next visit (presumably after OAuth is finished)
 * retries connect-seed instead of it staying silently un-seeded forever.
 *
 * `markSeededIfFirstVisit()`'s underlying `updatePluginSettings()` lock has a bounded number of
 * attempts (issue #340) and can throw {@see PluginsConfigStoreLockedException}
 * on exhaustion — exactly the shape a prefetch-plus-click double GET produces, the two racing
 * each other for the same lock (issue #422). That is lock contention, not a broken plugin, so it
 * degrades the same way as `render()` failing below: skip the seed for this one visit and render
 * the page normally, instead of a 500 for the whole settings page.
 *
 * Issue #604: this plugin's declared `ui.css`/`ui.js` (`manifest.json`, not `$page->render()`'s
 * own output) are inserted into this page's shell (settings/plugin/page.html.twig's
 * `stylesheets`/`javascripts` blocks) regardless of which of the two branches above runs — an
 * asset load failure is unrelated to whether the plugin's own settings markup rendered. This only
 * covers this shell page: a route the plugin declares itself via its own `plugin-routing.yaml`
 * (its own OAuth callback, for instance) renders its own response with no help from this
 * controller, and is responsible for its own `<link>`/`<script>` tags if it wants any.
 *
 * {@see PluginUiAssetsResolver} resolves those declared paths to URLs here, in the controller —
 * never in the template itself — so that a stale or malformed `ui` entry degrades to omitting
 * that one tag instead of taking down `renderFailed`'s own graceful-degradation branch along with
 * the happy path, which is exactly the failure this controller otherwise goes out of its way to
 * avoid.
 *
 * Issue #595: `$page->render()`'s output is a raw HTML string from unverified plugin code, so it
 * is passed through {@see PluginHtmlSanitizer} before being handed to `page.html.twig`, which
 * prints it with `|raw`.
 */
final class PluginSettingsController
{
    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly SettingsPageRegistry $settingsPages,
        private readonly SyncRegistry $syncRegistry,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly MessageBusInterface $messageBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly PluginUiAssetsResolver $pluginUiAssets,
        private readonly PluginHtmlSanitizer $htmlSanitizer,
        private readonly string $oauthCallbackFixedPort,
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

        $syncReviewUrl = null;
        if ($this->syncRegistry->findByPluginId($id) !== null) {
            try {
                $alreadySeeded = $this->markSeededIfFirstVisit($id);
            } catch (PluginsConfigStoreLockedException $exception) {
                // The lock is contended (e.g. a browser prefetch racing the user's own click,
                // issue #422) rather than broken, so this must degrade like any other lock
                // contention: skip the seed for this visit and fall through to a normal render.
                // The next successful visit picks the seed back up.
                $this->logger->info('Could not mark connect-seed as seeded because the plugins config store lock was exhausted; skipping seed dispatch for this visit.', [
                    'pluginId' => $pluginId,
                    'exception' => $exception,
                ]);

                $alreadySeeded = true;
            }

            if (!$alreadySeeded) {
                $this->messageBus->dispatch(new SyncSeedMessage((string) $id));

                // Only the visit that actually queued the seed shows the notice — a later visit
                // (syncSeeded already true) renders the plugin's own settings markup with nothing
                // above it, same as before connect-seed existed.
                $syncReviewUrl = $this->urlGenerator->generate('settings_sync_review_index');
            }
        }

        $pluginUi = $this->pluginUiAssets->resolve($id);

        try {
            $content = $this->htmlSanitizer->sanitize($page->render());
        } catch (\Throwable $exception) {
            $this->logger->error('Plugin settings page render() failed.', [
                'pluginId' => $pluginId,
                'exception' => $exception,
            ]);

            return new Response($this->twig->render('settings/plugin/page.html.twig', [
                'pluginId' => $pluginId,
                'pluginName' => $plugin->manifest->name,
                'pluginUi' => $pluginUi,
                'content' => null,
                'renderFailed' => true,
                'oauthCallbackWarning' => $this->oauthCallbackWarning(),
                'syncReviewUrl' => $syncReviewUrl,
            ]));
        }

        return new Response($this->twig->render('settings/plugin/page.html.twig', [
            'pluginId' => $pluginId,
            'pluginName' => $plugin->manifest->name,
            'pluginUi' => $pluginUi,
            'content' => $content,
            'renderFailed' => false,
            'oauthCallbackWarning' => $this->oauthCallbackWarning(),
            'syncReviewUrl' => $syncReviewUrl,
        ]));
    }

    /**
     * Issue #871: true when the host's fixed-port OAuth-redirect listener could not bind (the
     * `OAUTH_CALLBACK_FIXED_PORT` env var, see native/supervisor/env.js, is '0') — the settings
     * shell then shows a generic warning, naming no specific source, since the host has no
     * knowledge of which plugins' OAuth providers actually compare the callback port.
     */
    private function oauthCallbackWarning(): bool
    {
        return $this->oauthCallbackFixedPort === '0';
    }

    /**
     * Returns whether the plugin was already seeded before this call, atomically setting the
     * flag if not — the check and the set happen under the same
     * {@see PluginsConfigStore::updatePluginSettings()} lock, so two concurrent requests can
     * never both observe "not seeded yet" and both dispatch {@see SyncSeedMessage}.
     */
    private function markSeededIfFirstVisit(PluginId $id): bool
    {
        $alreadySeeded = false;
        $this->pluginsConfigStore->updatePluginSettings($id, static function (array $settings) use (&$alreadySeeded): array {
            if (($settings['syncSeeded'] ?? false) === true) {
                $alreadySeeded = true;

                return $settings;
            }

            $settings['syncSeeded'] = true;

            return $settings;
        });

        return $alreadySeeded;
    }
}
