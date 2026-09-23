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

namespace App\Service;

use App\Entity\Anime;
use App\Entity\AnimeImage;
use App\Entity\AnimeName;
use App\Entity\AnimeSource;
use App\Entity\Label;
use App\Entity\SeriesAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns an Anime entity into a plain array for Twig. Twig's strict_variables is enabled in
 * the test env, and SeriesAnime-only accessors like getEpisodesCount() do not exist on
 * MovieAnime, so the type-dependent fields are resolved here instead of via instanceof
 * checks in templates. Shared by AnimeController (full page) and AnimeEditableController
 * (HTMX fragments, issue #103) so both render the exact same view shape.
 */
final class AnimeViewFactory
{
    private const DEFAULT_LOCALE = 'en';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /** @return array<string, mixed> */
    public function serialize(Anime $anime): array
    {
        return [
            'id' => $anime->id,
            'title' => $anime->getTitle(),
            'summary' => $anime->getSummary($this->resolveLocale()),
            'type' => $anime->getType()->value,
            'production_status' => $anime->getProductionStatus()->value,
            'watch_status' => $anime->getWatchStatus()->value,
            'user_rating' => $anime->getUserRating()?->value,
            'is_series' => $anime instanceof SeriesAnime,
            'episodes_count' => $anime instanceof SeriesAnime ? $anime->getEpisodesCount() : null,
            'watched_episodes' => $anime instanceof SeriesAnime ? $anime->getWatchedEpisodes() : null,
            'duration_minutes' => $anime->getDurationMinutes(),
            'studios' => array_map(static fn (Studio $studio): array => ['id' => $studio->id, 'name' => $studio->name], $anime->getStudios()->toArray()),
            'countries' => $anime->getCountries() ?? [],
            'storage' => $this->serializeStorage($anime->getStorage(), $anime->getStoragePath()),
            'cover' => $anime->getCover(),
            'images' => array_map(static fn (AnimeImage $image): string => $image->source, $anime->getImages()->toArray()),
            'names' => array_map(
                static fn (AnimeName $name): array => ['name' => $name->name, 'type' => $name->type->value],
                $anime->getNames()->toArray(),
            ),
            'genres' => array_map(static fn ($code): string => $code->value, $anime->getGenreCodes()),
            'themes' => array_map(static fn ($code): string => $code->value, $anime->getThemeCodes()),
            'demographic' => $anime->getDemographic()?->value,
            'notes' => $anime->getNotes(),
            'sources' => array_map(
                static fn (AnimeSource $source): array => ['url' => $source->url, 'domain' => (string) parse_url($source->url, PHP_URL_HOST)],
                $anime->getSources()->toArray(),
            ),
            'labels' => array_map(
                static fn (Label $label): array => ['id' => $label->id, 'name' => $label->name],
                $anime->getLabels()->toArray(),
            ),
        ];
    }

    /**
     * Same locale source as the {% trans %} tags in the templates (app.request.locale,
     * negotiated by LocaleSubscriber from the Accept-Language header, issue #87).
     */
    private function resolveLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? self::DEFAULT_LOCALE;
    }

    /**
     * 'path' is the anime's own file/folder path (template label: "Путь к файлу"), not
     * the storage's root — composed from storage.path + Anime::$storagePath (the
     * top-level entry the scanner linked this anime to, Таск 3). Falls back to the bare
     * storage root only when $storagePath is null (anime added before the scanner ran,
     * or never linked to a specific file). This is the only current caller of
     * serializeStorage(); a future storage-management screen listing Storage rows on
     * their own would need the plain root path and should not reuse this method as-is.
     *
     * @return array{name: string, type: string, path: string, path_available: bool}|null
     */
    private function serializeStorage(?Storage $storage, ?string $storagePath): ?array
    {
        if ($storage === null) {
            return null;
        }

        $path = $storagePath === null
            ? $storage->getPath()
            : rtrim($storage->getPath(), '\\/').\DIRECTORY_SEPARATOR.$storagePath;

        return [
            'name' => $storage->getName(),
            'type' => $storage->getType()->value,
            'path' => $path,
            // Checked here (server-side, at page load), not on button click: FrankenPHP runs
            // locally on the same machine as the user's files, so this is a real filesystem
            // check, not a network round-trip.
            'path_available' => is_readable($path),
        ];
    }
}
