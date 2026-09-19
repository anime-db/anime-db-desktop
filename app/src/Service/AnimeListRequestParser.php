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

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Repository\AnimeListFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Turns raw GET query parameters of the anime list endpoint (issue #74) into the typed
 * AnimeListFilter value object and a validated [limit, offset] pagination pair. Kept out
 * of AnimeListController so the controller stays a thin HTTP adapter and this parsing can
 * be unit-tested without going through a real Request-handling stack.
 */
final class AnimeListRequestParser
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function parseFilter(Request $request): AnimeListFilter
    {
        $country = $this->assertScalarParam($request, 'countries');
        $country = \is_string($country) && $country !== '' ? $country : null;

        $name = $this->assertScalarParam($request, 'name');
        $name = \is_string($name) && $name !== '' ? $name : null;

        return new AnimeListFilter(
            watchStatuses: $this->parseEnumListParam($request, 'watch_status', WatchStatus::tryFrom(...)),
            types: $this->parseEnumListParam($request, 'type', AnimeType::tryFrom(...)),
            country: $country,
            name: $name,
            genres: $this->parseEnumListParam($request, 'genres', GenreCode::tryFrom(...)),
            studioIds: $this->parseIntListParam($request, 'studios'),
            labelIds: $this->parseIntListParam($request, 'labels'),
            themes: $this->parseEnumListParam($request, 'themes', ThemeCode::tryFrom(...)),
            userRatingFrom: $this->parseIntParam($request, 'user_rating_from'),
            userRatingTo: $this->parseIntParam($request, 'user_rating_to'),
            userRatingIsNull: $request->query->getBoolean('user_rating_none'),
            datePremiereFrom: $this->parseDateParam($request, 'date_premiere_from'),
            datePremiereTo: $this->parseDateParam($request, 'date_premiere_to'),
            datePremiereIsNull: $request->query->getBoolean('date_premiere_none'),
            dateEndFrom: $this->parseDateParam($request, 'date_end_from'),
            dateEndTo: $this->parseDateParam($request, 'date_end_to'),
            dateAddFrom: $this->parseDateParam($request, 'date_add_from'),
            dateAddTo: $this->parseDateParam($request, 'date_add_to'),
        );
    }

    /** @return array{0: int, 1: int} */
    public function parsePagination(Request $request): array
    {
        $limit = $this->parseIntParam($request, 'limit') ?? self::DEFAULT_LIMIT;
        $offset = $this->parseIntParam($request, 'offset') ?? 0;

        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
    }

    public function parseOptionalString(Request $request, string $name): ?string
    {
        $raw = $this->assertScalarParam($request, $name);

        return $raw !== null ? (string) $raw : null;
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
            if ($value === null) {
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
        if ($raw === null || $raw === '') {
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
        if ($raw === null || $raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $raw);
        if ($date === false) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a valid Y-m-d date for "%s"', $raw, $name));
        }

        return $date;
    }

    /**
     * Request::query is an InputBag: it already rejects a non-scalar value (throwing
     * Symfony's own BadRequestException) before this method ever sees it, so it can only
     * ever return a string or null.
     */
    private function assertScalarParam(Request $request, string $name): ?string
    {
        return $request->query->get($name);
    }
}
