<?php

declare(strict_types=1);

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

namespace App\Controller;

use App\Entity\Anime;
use App\Service\Plugin\Filler\SaturationQueue;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Fill from links" in the entry card's actions menu (issue #1049): queues a forced saturation of
 * the record (every recognising filler is called, whatever its filled_at marker says) and goes
 * back to the entry with a "started in the background" flash. Nothing is reported when it ends.
 */
final class AnimeSaturateController
{
    public function __construct(
        private readonly SaturationQueue $queue,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/anime/{id}/saturate', name: 'anime_saturate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saturate(Anime $anime, Request $request): Response
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before it can be saturated.');

        $token = new CsrfToken('anime_saturate_'.$animeId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $this->queue->enqueue($animeId, force: true);

        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('success', [
            'text' => $this->translator->trans('anime_detail.flash_saturate_started'),
            'link_url' => null,
            'link_label' => null,
        ]);

        return new RedirectResponse($this->urlGenerator->generate('anime_show', ['id' => $animeId]));
    }
}
