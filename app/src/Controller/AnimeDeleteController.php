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
use App\Service\AnimeDeleteFlash;
use App\Service\AnimeDeleteOutcome;
use App\Service\AnimeDeleteService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Delete" in the entry card's actions menu (issue #916). The confirmation is a `data-confirm`
 * dialog on the form (see inline-handlers.js); the work and every refusal live in
 * {@see AnimeDeleteService}. Success goes back to the catalog, a refusal back to the entry; both
 * with a flash message ({@see AnimeDeleteFlash}).
 */
final class AnimeDeleteController
{
    public function __construct(
        private readonly AnimeDeleteService $deleteService,
        private readonly AnimeDeleteFlash $flash,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/anime/{id}/delete', name: 'anime_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Anime $anime, Request $request): Response
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before it can be deleted.');

        $token = new CsrfToken('anime_delete_'.$animeId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $title = $anime->getTitle();
        $outcome = $this->deleteService->delete($anime);
        $this->flash->add($request, $outcome, $title);

        return new RedirectResponse($outcome === AnimeDeleteOutcome::Deleted
            ? $this->urlGenerator->generate('home_index')
            : $this->urlGenerator->generate('anime_show', ['id' => $animeId]));
    }
}
