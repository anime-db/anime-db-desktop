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

namespace App\Controller;

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetPendingUpdate;
use App\Entity\Anime;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\PluginHtmlSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Async widget slot (issue #212): the anime detail page renders a placeholder per active plugin
 * widget (`hx-get` + `hx-trigger="load"`, see anime/show.html.twig), and this controller answers
 * each of those requests independently. A single failing widget therefore never blocks the page
 * or any other widget — the request that failed just swaps in the error fragment instead.
 *
 * This is a plain cacheable GET keyed on the three URL parameters (pluginId, widgetName,
 * entryId) — but, despite reading no session/cookie state itself, its response is not a pure
 * function of the URL: an OAuth-aware widget's render() (e.g. Shikimori's "new" widget) can
 * depend on the plugin's own credential/token state stored in plugins.json, which the URL does
 * not capture. Successful responses therefore carry a short-lived, `private` Cache-Control (issue
 * #364) — short so a stale token/reauth state does not linger past what a reload would show
 * anyway, `private` so a shared cache never reuses one user's personalized content for another —
 * accepting up to {@see self::CACHE_MAX_AGE_SECONDS} of staleness for that personalized content
 * in exchange for not re-hitting the plugin's own API on every reload. No no-store mechanism is
 * introduced for the OAuth-aware case; the staleness window is accepted as-is. The error fragment
 * is not cached at all — a transient plugin error must not be pinned past the request that
 * observed it.
 *
 * Issue #595: a widget's `render()` output is a raw HTML string from unverified plugin code, so
 * it is passed through {@see PluginHtmlSanitizer} before becoming this controller's response
 * body — a sanitizer failure is treated the same as `render()` itself throwing, degrading to
 * this widget's own error fragment.
 *
 * Issue #703/#684: a widget that still has no data to show (e.g. its own background job has not
 * finished yet) signals that by prefixing its returned HTML with {@see WidgetPendingUpdate::MARKER}.
 * Such a response is served without the usual max-age, so it is never served stale out of the
 * browser's HTTP cache the next time this slot's URL is requested (page reload, navigating back
 * to it) once the real data is ready — this route is not polled or re-requested by anything on
 * its own; the slot itself only ever fires once, on `hx-trigger="load"`. The marker is a widget
 * contract concern, not a host implementation detail, since a plugin author is the one who needs
 * to emit it — {@see EntryWidgetInterface::render()} / {@see CatalogWidgetInterface::render()}
 * currently only document the plain-`string` case; documenting this prefix there too (and
 * exposing its value as a public constant of the contract) is tracked as
 * `anime-db/anime-db-plugin-contracts#90`, not part of this host-side change.
 */
final class PluginWidgetController
{
    private const int CACHE_MAX_AGE_SECONDS = 300;

    public function __construct(
        private readonly EntryWidgetRegistry $entryWidgets,
        private readonly CatalogWidgetRegistry $catalogWidgets,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly PluginHtmlSanitizer $htmlSanitizer,
    ) {
    }

    #[Route('/plugin/{pluginId}/widget/{widgetName}', name: 'plugin_widget', methods: ['GET'])]
    public function render(string $pluginId, string $widgetName, Request $request): Response
    {
        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $entryWidget = $this->entryWidgets->find($id, $widgetName);
        if ($entryWidget !== null) {
            return $this->renderEntryWidget($entryWidget, $id, $request);
        }

        $catalogWidget = $this->catalogWidgets->find($id, $widgetName);
        if ($catalogWidget !== null) {
            return $this->renderCatalogWidget($catalogWidget, $id, $request);
        }

        throw new NotFoundHttpException(\sprintf('Widget "%s" of plugin "%s" not found or disabled.', $widgetName, $pluginId));
    }

    private function renderEntryWidget(EntryWidgetInterface $widget, PluginId $pluginId, Request $request): Response
    {
        $entryId = $request->query->get('entryId');
        if (!\is_string($entryId) || $entryId === '' || !ctype_digit($entryId)) {
            throw new BadRequestHttpException('Query parameter "entryId" must be a positive integer.');
        }

        $anime = $this->entityManager->find(Anime::class, (int) $entryId);
        if (!$anime instanceof Anime) {
            throw new NotFoundHttpException(\sprintf('Catalog record #%s not found.', $entryId));
        }

        try {
            $raw = $widget->render(new AnimeId((int) $entryId));
        } catch (\Throwable $e) {
            return $this->renderWidgetError($pluginId, $request, $e);
        }

        return $this->widgetResponse($raw);
    }

    private function renderCatalogWidget(CatalogWidgetInterface $widget, PluginId $pluginId, Request $request): Response
    {
        try {
            $raw = $widget->render();
        } catch (\Throwable $e) {
            return $this->renderWidgetError($pluginId, $request, $e);
        }

        return $this->widgetResponse($raw);
    }

    private function renderWidgetError(PluginId $pluginId, Request $request, \Throwable $e): Response
    {
        $this->logger->error('Plugin widget render() failed.', [
            'pluginId' => (string) $pluginId,
            'exception' => $e,
        ]);

        return new Response($this->twig->render('plugin/_widget_error.html.twig', [
            'pluginId' => (string) $pluginId,
            'retryUrl' => $request->getRequestUri(),
        ]));
    }

    /**
     * Strips {@see WidgetPendingUpdate::MARKER} when present and routes to the cached or
     * uncached response accordingly — see the class docblock.
     */
    private function widgetResponse(string $raw): Response
    {
        $pendingUpdate = str_starts_with($raw, WidgetPendingUpdate::MARKER);
        $html = $this->htmlSanitizer->sanitize($pendingUpdate ? substr($raw, \strlen(WidgetPendingUpdate::MARKER)) : $raw);

        return $pendingUpdate ? new Response($html) : $this->cacheableResponse($html);
    }

    /**
     * Private, not public: an entry widget can reflect the plugin's own account/credential
     * state from plugins.json, so the response must not be reusable across users/sessions by
     * a shared cache — even though this desktop app has no such shared cache in practice.
     */
    private function cacheableResponse(string $html): Response
    {
        $response = new Response($html);
        $response->setPrivate();
        $response->setMaxAge(self::CACHE_MAX_AGE_SECONDS);

        return $response;
    }
}
