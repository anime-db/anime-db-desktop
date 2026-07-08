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

use App\Entity\Label;
use App\Repository\LabelRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autocomplete source for the anime detail label tag-input (issue #104): the whole label
 * catalogue is small enough for a desktop, single-user collection that filtering happens
 * client-side, so this endpoint just lists every known label rather than taking a search term.
 */
final class LabelController
{
    public function __construct(private readonly LabelRepository $labels)
    {
    }

    #[Route('/labels', name: 'labels_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'labels' => array_map(
                static fn (Label $label): array => ['id' => $label->id, 'name' => $label->name],
                $this->labels->findAllOrderedByName(),
            ),
        ]);
    }
}
