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

namespace App\Controller\Settings;

use App\Entity\Exception\InvalidNameException;
use App\Entity\Label;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class LabelController
{
    public function __construct(
        private readonly LabelRepository $labels,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/labels', name: 'settings_labels_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return new Response($this->twig->render('settings/label/index.html.twig', [
            'labels' => $this->labels->findAllOrderedByName(),
            'labelCounts' => $this->labels->countAnimeByLabel(),
            'error' => $request->query->get('error'),
        ]));
    }

    #[Route('/settings/labels', name: 'settings_labels_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_labels_add', $request);

        try {
            $label = new Label((string) $request->request->get('name', ''));
        } catch (InvalidNameException) {
            return new RedirectResponse($this->urlGenerator->generate('settings_labels_index', ['error' => 'empty_name']));
        }

        $this->entityManager->persist($label);
        $this->entityManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('settings_labels_index'));
    }

    #[Route('/settings/labels/{id}/rename', name: 'settings_labels_rename', methods: ['POST'])]
    public function rename(Label $label, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_labels_rename_'.$label->id, $request);

        try {
            $label->rename((string) $request->request->get('name', ''));
        } catch (InvalidNameException) {
            return new RedirectResponse($this->urlGenerator->generate('settings_labels_index', ['error' => 'empty_name']));
        }

        $this->entityManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('settings_labels_index'));
    }

    #[Route('/settings/labels/{id}/delete', name: 'settings_labels_delete', methods: ['POST'])]
    public function delete(Label $label, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_labels_delete_'.$label->id, $request);

        foreach ($label->getAnimes() as $anime) {
            $anime->removeLabel($label);
        }

        $this->entityManager->remove($label);
        $this->entityManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('settings_labels_index'));
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
