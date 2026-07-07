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
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\ValueObject\Exception\InvalidRatingException;
use App\Repository\AnimeListFilter;
use App\Repository\AnimeRepository;
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
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly AnimeRepository $animeRepository,
        private readonly AnimeListSortResolver $sortResolver,
        private readonly AppSettingsProvider $settings,
    ) {
    }

    #[Route('/anime', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = $this->parseFilter($request);
        $sortRaw = $this->assertScalarParam($request, 'sort');
        $directionRaw = $this->assertScalarParam($request, 'direction');
        $sort = $this->sortResolver->resolve(null !== $sortRaw ? (string) $sortRaw : null, null !== $directionRaw ? (string) $directionRaw : null);
        [$limit, $offset] = $this->parsePagination($request);

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

    private function parseFilter(Request $request): AnimeListFilter
    {
        $watchStatusRaw = $this->assertScalarParam($request, 'watch_status');
        $watchStatus = null !== $watchStatusRaw ? WatchStatus::tryFrom((string) $watchStatusRaw) : null;
        if (null === $watchStatus) {
            throw new BadRequestHttpException('watch_status is required and must be one of: '.implode(', ', array_column(WatchStatus::cases(), 'value')));
        }

        $type = $this->parseEnumParam($request, 'type', AnimeType::tryFrom(...));

        $country = $this->assertScalarParam($request, 'countries');
        $country = \is_string($country) && '' !== $country ? $country : null;

        return new AnimeListFilter(
            watchStatus: $watchStatus,
            type: $type,
            country: $country,
            genres: $this->parseEnumListParam($request, 'genres', GenreCode::tryFrom(...)),
            studioIds: $this->parseIntListParam($request, 'studios'),
            labelIds: $this->parseIntListParam($request, 'labels'),
            userRatingFrom: $this->parseIntParam($request, 'user_rating_from'),
            userRatingTo: $this->parseIntParam($request, 'user_rating_to'),
            datePremiereFrom: $this->parseDateParam($request, 'date_premiere_from'),
            datePremiereTo: $this->parseDateParam($request, 'date_premiere_to'),
            dateEndFrom: $this->parseDateParam($request, 'date_end_from'),
            dateEndTo: $this->parseDateParam($request, 'date_end_to'),
            dateAddFrom: $this->parseDateParam($request, 'date_add_from'),
            dateAddTo: $this->parseDateParam($request, 'date_add_to'),
        );
    }

    /** @return array{0: int, 1: int} */
    private function parsePagination(Request $request): array
    {
        $limit = $this->parseIntParam($request, 'limit') ?? self::DEFAULT_LIMIT;
        $offset = $this->parseIntParam($request, 'offset') ?? 0;

        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
    }

    /**
     * @template T of \UnitEnum
     *
     * @param callable(string): (T|null) $tryFrom
     *
     * @return T|null
     */
    private function parseEnumParam(Request $request, string $name, callable $tryFrom): ?object
    {
        $raw = $this->assertScalarParam($request, $name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        $value = $tryFrom((string) $raw);
        if (null === $value) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a valid value for "%s"', $raw, $name));
        }

        return $value;
    }

    /**
     * @template T of \UnitEnum
     *
     * @param callable(string): (T|null) $tryFrom
     *
     * @return list<T>
     */
    private function parseEnumListParam(Request $request, string $name, callable $tryFrom): array
    {
        $values = [];
        foreach ($this->queryList($request, $name) as $raw) {
            $value = $tryFrom((string) $raw);
            if (null === $value) {
                throw new BadRequestHttpException(\sprintf('"%s" is not a valid value for "%s[]"', $raw, $name));
            }
            $values[] = $value;
        }

        return $values;
    }

    /** @return list<int> */
    private function parseIntListParam(Request $request, string $name): array
    {
        $values = [];
        foreach ($this->queryList($request, $name) as $raw) {
            if (!is_numeric($raw)) {
                throw new BadRequestHttpException(\sprintf('"%s" is not a valid value for "%s[]"', $raw, $name));
            }
            $values[] = (int) $raw;
        }

        return $values;
    }

    /** @return list<mixed> */
    private function queryList(Request $request, string $name): array
    {
        if (!$request->query->has($name)) {
            return [];
        }

        $value = $request->query->all()[$name] ?? [];

        return \is_array($value) ? array_values($value) : [$value];
    }

    private function parseIntParam(Request $request, string $name): ?int
    {
        $raw = $this->assertScalarParam($request, $name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a valid value for "%s"', $raw, $name));
        }

        return (int) $raw;
    }

    private function parseDateParam(Request $request, string $name): ?\DateTimeImmutable
    {
        $raw = $this->assertScalarParam($request, $name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $raw);
        if (false === $date) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a valid Y-m-d date for "%s"', $raw, $name));
        }

        return $date;
    }

    private function assertScalarParam(Request $request, string $name): string|int|float|bool|null
    {
        $raw = $request->query->get($name);
        if (\is_array($raw)) {
            throw new BadRequestHttpException(\sprintf('"%s" must be a single value, not a list', $name));
        }

        return $raw;
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
