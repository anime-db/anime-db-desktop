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
use App\Entity\Label;
use App\Entity\ValueObject\Exception\InvalidRatingException;
use App\Repository\AnimeFacetEntityBucket;
use App\Repository\AnimeFacets;
use App\Repository\AnimeFacetValueBucket;
use App\Repository\AnimeListFilter;
use App\Repository\AnimeRepository;
use App\Service\AnimeListRequestParser;
use App\Service\AnimeListSortResolver;
use App\Service\AppSettingsProvider;
use App\Service\Search\AnimeSearchResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backend-only anime list endpoint (issue #74): filtering, whitelisted sorting and
 * LIMIT/OFFSET pagination. Serves both the classic and infinite-scroll frontends with the
 * same response shape — only the frontend load-more trigger differs, left to a later part
 * of the same decomposition.
 *
 * The list search box (issue #199) reuses the existing "name" filter parameter: when set,
 * this controller first tries to resolve it to concrete anime ids via Meilisearch
 * (AnimeSearchResolver); only if Meilisearch is unreachable does it leave AnimeListFilter::
 * $name untouched, which makes AnimeRepository fall back to the FTS5 quick-filter (#195).
 */
final class AnimeListController
{
    public function __construct(
        private readonly AnimeRepository $animeRepository,
        private readonly AnimeListRequestParser $requestParser,
        private readonly AnimeListSortResolver $sortResolver,
        private readonly AppSettingsProvider $settings,
        private readonly AnimeSearchResolver $searchResolver,
    ) {
    }

    #[Route('/anime', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = $this->resolveFilter($request);

        $sort = $this->sortResolver->resolve(
            $this->requestParser->parseOptionalString($request, 'sort'),
            $this->requestParser->parseOptionalString($request, 'direction'),
        );
        [$limit, $offset] = $this->requestParser->parsePagination($request);

        try {
            $total = $this->animeRepository->countByFilter($filter);
            $items = $this->animeRepository->findByFilter($filter, $sort, $limit, $offset);
        } catch (InvalidRatingException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            'items' => array_map($this->serializeAnime(...), $items),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'pagination_mode' => $this->settings->getPaginationMode()->value,
        ]);
    }

    /**
     * Counters for the eight filter-panel sections (issue #666), accepting the same filter
     * parameters as GET /anime. Reuses the same $name → Meilisearch id resolution as list()
     * so a facet count under an active search box narrows to the search result the same way
     * the list itself does.
     *
     * Also carries the unfiltered catalog size (issue #688, `catalog_total`) so the frontend
     * can build "Shown X of Y" from this single response instead of a separate request — the
     * filter parameters above never apply to that count.
     */
    #[Route('/anime/facets', methods: ['GET'])]
    public function facets(Request $request): JsonResponse
    {
        $filter = $this->resolveFilter($request);

        try {
            $facets = $this->animeRepository->facetsByFilter($filter);
        } catch (InvalidRatingException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return new JsonResponse($this->serializeFacets($facets));
    }

    private function resolveFilter(Request $request): AnimeListFilter
    {
        $filter = $this->requestParser->parseFilter($request);
        if ($filter->name !== null) {
            $ids = $this->searchResolver->tryResolveIds($filter->name);
            if ($ids !== null) {
                $filter = $filter->withIds($ids);
            }
        }

        return $filter;
    }

    /** @return array<string, mixed> */
    private function serializeFacets(AnimeFacets $facets): array
    {
        return [
            'catalog_total' => $facets->catalogTotal,
            'watch_status' => array_map($this->serializeValueBucket(...), $facets->watchStatuses),
            'type' => array_map($this->serializeValueBucket(...), $facets->types),
            'date_premiere_decade' => array_map($this->serializeValueBucket(...), $facets->datePremiereDecades),
            'user_rating' => array_map($this->serializeValueBucket(...), $facets->userRatings),
            'labels' => array_map($this->serializeEntityBucket(...), $facets->labels),
            'genres' => array_map($this->serializeValueBucket(...), $facets->genres),
            'themes' => array_map($this->serializeValueBucket(...), $facets->themes),
            'studios' => array_map($this->serializeEntityBucket(...), $facets->studios),
        ];
    }

    /** @return array{value: string, count: int} */
    private function serializeValueBucket(AnimeFacetValueBucket $bucket): array
    {
        return ['value' => $bucket->value, 'count' => $bucket->count];
    }

    /** @return array{id: int, name: string, count: int} */
    private function serializeEntityBucket(AnimeFacetEntityBucket $bucket): array
    {
        return ['id' => $bucket->id, 'name' => $bucket->name, 'count' => $bucket->count];
    }

    /** @return array<string, mixed> */
    private function serializeAnime(Anime $anime): array
    {
        return [
            'id' => $anime->id,
            'title' => $anime->getTitle(),
            'type' => $anime->getType()->value,
            'watch_status' => $anime->getWatchStatus()->value,
            'user_rating' => $anime->getUserRating()?->value,
            'date_premiere' => $anime->getDatePremiere()?->format('Y-m-d'),
            'date_end' => $anime->getDateEnd()?->format('Y-m-d'),
            'cover' => $anime->getCover(),
            'labels' => array_map(static fn (Label $label): string => $label->name, $anime->getLabels()->toArray()),
        ];
    }
}
