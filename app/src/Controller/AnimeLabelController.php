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
use App\Entity\Label;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Replaces the label set attached to an anime (issue #104): the tag-input on the anime detail
 * page always submits the full desired list of label names, so this action syncs the anime's
 * labels to exactly that set rather than adding/removing one at a time. A name that does not
 * match an existing label is created on the fly, mirroring the Jira "Labels" field it is
 * modelled on.
 */
final class AnimeLabelController
{
    public function __construct(
        private readonly LabelRepository $labels,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/anime/{id}/labels', name: 'anime_labels_update', methods: ['POST'])]
    public function update(Anime $anime, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $token = new CsrfToken('anime_labels_update_'.$anime->id, (string) ($payload['token'] ?? ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $labels = array_map(
            $this->findOrCreateLabel(...),
            $this->normalizeNames($payload['names'] ?? []),
        );

        foreach ($anime->getLabels()->toArray() as $existingLabel) {
            if (!\in_array($existingLabel, $labels, true)) {
                $anime->removeLabel($existingLabel);
            }
        }
        foreach ($labels as $label) {
            $anime->addLabel($label);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'labels' => array_map(
                static fn (Label $label): array => ['id' => $label->id, 'name' => $label->name],
                $anime->getLabels()->toArray(),
            ),
        ]);
    }

    private function findOrCreateLabel(string $name): Label
    {
        $label = $this->labels->findOneByName($name);
        if (null !== $label) {
            return $label;
        }

        $label = new Label($name);
        $this->entityManager->persist($label);

        return $label;
    }

    /**
     * @return list<string>
     */
    private function normalizeNames(mixed $names): array
    {
        if (!\is_array($names)) {
            throw new BadRequestHttpException('"names" must be an array.');
        }

        $normalized = [];
        foreach ($names as $name) {
            if (!\is_string($name)) {
                throw new BadRequestHttpException('"names" must contain only strings.');
            }

            $trimmed = trim($name);
            if ('' !== $trimmed && !\in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }
}
