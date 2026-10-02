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
use App\Service\Plugin\FillerAvailabilityPresenter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Renders the anime list page shell (issue #76): the grid and its pagination controls are
 * populated client-side by anime-list.js, which fetches AnimeListController's JSON endpoint
 * (issue #74). This controller only needs to hand the page its static markup.
 *
 * Also decides whether to show the onboarding invitation (issue #179, Таск 2 шаг 5, reworked by
 * issue #835): there is no dedicated "installed" flag (see decisions.md — Вопросы 6/7), so
 * "nothing to show" is inferred purely from the catalog having no Anime rows — unlike before
 * #835, a Storage already being configured no longer hides it, since an empty catalog looks
 * equally broken either way and a configured-but-unscanned storage still needs its own call to
 * action ({@see StorageRepository::findAllScannable()} below).
 *
 * Issue #720: this is the catalog widgets' counterpart to AnimeController's entry widgets — the
 * only place in the app that requests {@see CatalogWidgetRegistry::findAllActive()}, so an
 * active catalog widget has somewhere to render at all.
 *
 * Issue #833: the onboarding invitation's third card ("Search in plugins") reuses
 * {@see FillerAvailabilityPresenter} — the same three-state "why is there no active filler
 * plugin" resolution the search-plugins screen itself falls back to when opened directly.
 */
final class HomeController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly StorageRepository $storages,
        private readonly AnimeRepository $animeRepository,
        private readonly CatalogWidgetRegistry $catalogWidgets,
        private readonly AppSettingsProvider $settings,
        private readonly FillerAvailabilityPresenter $fillerAvailability,
    ) {
    }

    #[Route('/', name: 'home_index', methods: ['GET'])]
    public function index(): Response
    {
        $showOnboarding = !$this->animeRepository->hasAny();
        $scannableStorages = $showOnboarding ? $this->storages->findAllScannable() : [];
        $hasActiveFiller = $showOnboarding && $this->fillerAvailability->hasActiveFiller();

        return new Response($this->twig->render('anime/list.html.twig', [
            'showOnboarding' => $showOnboarding,
            'hasScannableStorage' => $scannableStorages !== [],
            // Only set when there is exactly one candidate (issue #835): the invitation card then
            // submits a scan for that storage directly instead of only linking to the storage list.
            'singleScannableStorageId' => \count($scannableStorages) === 1 ? $scannableStorages[0]->id : null,
            'widgets' => $this->catalogWidgets->findAllActive(),
            // Issue #820: read once here so the template can render each section's
            // aria-expanded/hidden state from the saved value on first paint, instead of every
            // section flashing open before anime-list-filters.js reconciles it client-side.
            'collapsedFilterSections' => $this->settings->getCollapsedFilterSections(),
            'hasActiveFillerPlugin' => $hasActiveFiller,
            'noFillerState' => $showOnboarding && !$hasActiveFiller ? $this->fillerAvailability->describeUnavailable() : null,
        ]));
    }
}
