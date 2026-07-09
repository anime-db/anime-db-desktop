<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Anime;
use App\Service\AnimeViewFactory;
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
 */
final class AnimeController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AnimeViewFactory $viewFactory,
    ) {
    }

    #[Route('/anime/{id}', name: 'anime_show', methods: ['GET'])]
    public function show(Anime $anime): Response
    {
        return new Response($this->twig->render('anime/show.html.twig', [
            'anime' => $this->viewFactory->serialize($anime),
        ]));
    }
}
