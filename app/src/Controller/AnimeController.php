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

use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;
use App\Repository\DownloadRepository;
use App\Service\AnimeViewFactory;
use App\Service\Download\DownloadViewFactory;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\Filler\FillableFieldsPresenter;
use App\Service\Plugin\PluginUiAssetsResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Anime detail page: the skeleton layout, the read-only reference block (issue #101), the
 * external sources block and the "open storage folder" button (issue #105). Watch status,
 * rating, notes and episode progress are rendered by the same anime/_editable.html.twig
 * fragment that AnimeEditableController swaps in place via HTMX (issue #103). Labels
 * (issue #104, view side only — editing goes through AnimeLabelController) and the
 * cover/gallery are separate parts of the same decomposition.
 *
 * Plugin widgets (issue #212) render as HTMX placeholders here — one `hx-get` per active
 * {@see EntryWidgetRegistry} entry, loaded by PluginWidgetController after the page itself is
 * already on screen, so a slow or failing plugin API never blocks the initial render.
 *
 * Issue #604: a plugin behind at least one of those active widgets can also declare static
 * `ui.css`/`ui.js` files in its manifest — the host, not the plugin's own widget markup, inserts
 * the corresponding `<link>`/`<script>` tags into this page's shell (anime/show.html.twig's
 * `stylesheets`/`javascripts` blocks), which is what lets `script-src 'self'` work with no
 * per-plugin exception. A plugin with no active widget on this page contributes nothing here,
 * even if it declares `ui` — its markup simply is not reachable from this page.
 *
 * {@see PluginUiAssetsResolver} resolves those declared paths to URLs here, in the controller —
 * never in the template itself — because a resolution failure (a stale or malformed `ui` entry;
 * arbitrary-zip install, issue #251, does not verify a declared file exists) must degrade to
 * simply omitting that one tag, not to a 500 for the whole card: exactly the same "a single
 * failing widget never blocks the page" guarantee issue #212 already gives the widgets themselves.
 */
final class AnimeController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AnimeViewFactory $viewFactory,
        private readonly EntryWidgetRegistry $entryWidgets,
        private readonly FillableFieldsPresenter $fillableFieldsPresenter,
        private readonly PluginUiAssetsResolver $pluginUiAssets,
        private readonly DownloadRepository $downloads,
        private readonly DownloadViewFactory $downloadViewFactory,
    ) {
    }

    #[Route('/anime/{id}', name: 'anime_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Anime $anime): Response
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before it can be shown.');
        $widgets = $this->entryWidgets->findAllActive();

        return new Response($this->twig->render('anime/show.html.twig', [
            'anime' => $this->viewFactory->serialize($anime),
            'widgets' => $widgets,
            'plugins_ui' => $this->pluginsUiFor(array_column($widgets, 'pluginId')),
            'fillable_fields' => $this->fillableFieldsPresenter->build(),
            'downloads' => $this->downloadViewFactory->serializeList($this->downloads->findByAnime($animeId)),
            'downloads_unlink_error' => null,
            'delete_has_finished_downloads' => $this->downloads->hasFinishedForAnime($animeId),
        ]));
    }

    /**
     * @param list<string> $pluginIds
     *
     * @return list<array{pluginId: string, css: list<string>, js: list<string>}>
     */
    private function pluginsUiFor(array $pluginIds): array
    {
        $result = [];
        foreach (array_unique($pluginIds) as $pluginId) {
            $resolved = $this->pluginUiAssets->resolve(new PluginId($pluginId));
            if ($resolved['css'] !== [] || $resolved['js'] !== []) {
                $result[] = ['pluginId' => $pluginId, 'css' => $resolved['css'], 'js' => $resolved['js']];
            }
        }

        return $result;
    }
}
