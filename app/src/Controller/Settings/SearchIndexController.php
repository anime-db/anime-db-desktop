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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Own page for the search-index rebuild action (issue #822), split out of the main `/settings`
 * page it used to share with the locale/theme/pagination controls. `SettingsController::reindexSearch()`
 * still owns the POST endpoint and redirects here (PRG) with the outcome in `?status=`.
 */
final class SearchIndexController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route('/settings/search-index', name: 'settings_search_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $status = (string) $request->query->get('status', '');

        return new Response($this->twig->render('settings/search_index/index.html.twig', [
            'reindexStatus' => \in_array($status, ['success', 'error'], true) ? $status : null,
        ]));
    }
}
