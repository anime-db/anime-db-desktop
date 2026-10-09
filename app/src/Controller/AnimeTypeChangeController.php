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
use App\Entity\Enum\AnimeType;
use App\Entity\Exception\InvalidAnimeTypeChangeException;
use App\Service\AnimeTypeChangeFlash;
use App\Service\AnimeTypeChangeService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Change type…" in the entry card's actions menu (issue #1001). The dialog is part of the card
 * ({@see \App\Service\AnimeViewFactory} lists what each type would drop); the work and the refusal
 * while a sync runs live in {@see AnimeTypeChangeService}. A change that drops data (a series
 * becoming a movie) is applied only with the `confirm_loss` box ticked, which the dialog requires too.
 * Always goes back to the entry with a flash message.
 */
final class AnimeTypeChangeController
{
    public function __construct(
        private readonly AnimeTypeChangeService $changeService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AnimeTypeChangeFlash $flash,
    ) {
    }

    #[Route('/anime/{id}/change-type', name: 'anime_change_type', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function change(Anime $anime, Request $request): Response
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before its type can be changed.');

        $token = new CsrfToken('anime_change_type_'.$animeId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $targetType = AnimeType::tryFrom((string) $request->request->get('type'))
            ?? throw new BadRequestHttpException('Unknown anime type.');

        try {
            $outcome = $this->changeService->change($anime, $targetType, $request->request->getBoolean('confirm_loss'));
            $this->flash->add($request, $outcome, $targetType);
        } catch (InvalidAnimeTypeChangeException) {
            $this->flash->addInvalid($request);
        }

        return new RedirectResponse($this->urlGenerator->generate('anime_show', ['id' => $animeId]));
    }
}
