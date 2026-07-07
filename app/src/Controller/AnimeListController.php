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
use App\Entity\ValueObject\Exception\InvalidRatingException;
use App\Repository\AnimeRepository;
use App\Service\AnimeListRequestParser;
use App\Service\AnimeListSortResolver;
use App\Service\AppSettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backend-only anime list endpoint (issue #74): filtering, whitelisted sorting and
 * LIMIT/OFFSET pagination. Serves both the classic and infinite-scroll frontends with the
 * same response shape — only the frontend load-more trigger differs, left to a later part
 * of the same decomposition.
 */
final class AnimeListController
{
    public function __construct(
        private readonly AnimeRepository $animeRepository,
        private readonly AnimeListRequestParser $requestParser,
        private readonly AnimeListSortResolver $sortResolver,
        private readonly AppSettingsProvider $settings,
    ) {
    }

    #[Route('/anime', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = $this->requestParser->parseFilter($request);
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
        ];
    }
}
