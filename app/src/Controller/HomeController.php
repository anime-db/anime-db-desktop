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

use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\CatalogWidgetRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Renders the anime list page shell (issue #76): the grid and its pagination controls are
 * populated client-side by anime-list.js, which fetches AnimeListController's JSON endpoint
 * (issue #74). This controller only needs to hand the page its static markup.
 *
 * Also decides whether to show the onboarding banner (issue #179, Таск 2 шаг 5): there is no
 * dedicated "installed" flag (see decisions.md — Вопросы 6/7), so "wizard not completed" is
 * inferred purely from the catalog being empty (no Storage and no Anime rows). Both "skip"
 * actions leave the catalog empty, so the banner simply reappears on the next visit.
 *
 * Issue #720: this is the catalog widgets' counterpart to AnimeController's entry widgets — the
 * only place in the app that requests {@see CatalogWidgetRegistry::findAllActive()}, so an
 * active catalog widget has somewhere to render at all.
 */
final class HomeController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly StorageRepository $storages,
        private readonly AnimeRepository $animeRepository,
        private readonly CatalogWidgetRegistry $catalogWidgets,
        private readonly AppSettingsProvider $settings,
    ) {
    }

    #[Route('/', name: 'home_index', methods: ['GET'])]
    public function index(): Response
    {
        $showOnboarding = !$this->storages->hasAny() && !$this->animeRepository->hasAny();

        return new Response($this->twig->render('anime/list.html.twig', [
            'showOnboarding' => $showOnboarding,
            'widgets' => $this->catalogWidgets->findAllActive(),
            // Issue #820: read once here so the template can render each section's
            // aria-expanded/hidden state from the saved value on first paint, instead of every
            // section flashing open before anime-list-filters.js reconciles it client-side.
            'collapsedFilterSections' => $this->settings->getCollapsedFilterSections(),
        ]));
    }
}
