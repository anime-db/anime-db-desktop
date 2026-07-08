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
use App\Entity\AnimeName;
use App\Entity\AnimeSource;
use App\Entity\SeriesAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Anime detail page: the skeleton layout, the read-only reference block (issue #101), the
 * external sources block and the "open storage folder" button (issue #105). Watch
 * status/rating/notes/labels editing and the cover/gallery are separate parts of the same
 * decomposition (see the two-column body in anime/show.html.twig).
 *
 * The view is handed a plain array, not the Anime entity directly: Twig's
 * strict_variables is enabled in the test env, and SeriesAnime-only accessors like
 * getEpisodesCount() do not exist on MovieAnime, so the type-dependent fields are
 * resolved here instead of via instanceof checks in the template.
 */
final class AnimeController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route('/anime/{id}', name: 'anime_show', methods: ['GET'])]
    public function show(Anime $anime): Response
    {
        return new Response($this->twig->render('anime/show.html.twig', [
            'anime' => $this->serializeAnime($anime),
        ]));
    }

    /** @return array<string, mixed> */
    private function serializeAnime(Anime $anime): array
    {
        return [
            'title' => $anime->getTitle(),
            'type' => $anime->getType()->value,
            'production_status' => $anime->getProductionStatus()->value,
            'episodes_count' => $anime instanceof SeriesAnime ? $anime->getEpisodesCount() : null,
            'duration_minutes' => $anime->getDurationMinutes(),
            'studios' => array_map(static fn (Studio $studio): string => $studio->name, $anime->getStudios()->toArray()),
            'countries' => $anime->getCountries() ?? [],
            'storage' => $this->serializeStorage($anime->getStorage()),
            'names' => array_map(
                static fn (AnimeName $name): array => ['name' => $name->name, 'type' => $name->type->value],
                $anime->getNames()->toArray(),
            ),
            'genres' => array_map(static fn ($code): string => $code->value, $anime->getGenreCodes()),
            'notes' => $anime->getNotes(),
            'sources' => array_map(
                static fn (AnimeSource $source): array => ['url' => $source->url, 'domain' => (string) parse_url($source->url, PHP_URL_HOST)],
                $anime->getSources()->toArray(),
            ),
        ];
    }

    /** @return array{name: string, type: string, path: string, path_available: bool}|null */
    private function serializeStorage(?Storage $storage): ?array
    {
        if ($storage === null) {
            return null;
        }

        return [
            'name' => $storage->getName(),
            'type' => $storage->getType()->value,
            'path' => $storage->getPath(),
            // Checked here (server-side, at page load), not on button click: FrankenPHP runs
            // locally on the same machine as the user's files, so this is a real filesystem
            // check, not a network round-trip.
            'path_available' => is_readable($storage->getPath()),
        ];
    }
}
