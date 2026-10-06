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
use App\Repository\AnimeRepository;
use App\Service\Download\DownloadAdoptionRefusedException;
use App\Service\Download\DownloadOrphanAdopter;
use App\Service\Exception\QbittorrentClientException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * "Link to entry" for a torrent without a card: the page to pick a catalog entry (GET) and the
 * POST that {@see DownloadOrphanAdopter} turns into a `downloads` row. The same POST is sent by the
 * button on the "Add download" page's "already in the torrent client" error.
 */
final class DownloadAdoptController
{
    public function __construct(
        private readonly DownloadOrphanAdopter $adopter,
        private readonly AnimeRepository $animeRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/downloads/orphan/{infoHash}/adopt', name: 'download_adopt_orphan', requirements: ['infoHash' => '[0-9a-f]{40}'], methods: ['GET', 'POST'])]
    public function adopt(string $infoHash, Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render($infoHash);
        }

        $token = new CsrfToken('download_adopt_orphan_'.$infoHash, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $animeId = $request->request->getInt('anime');
        $anime = $animeId > 0 ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;
        if ($anime === null) {
            return $this->render($infoHash, error: 'download_new.error_anime_required');
        }

        try {
            $this->adopter->adopt($infoHash, $animeId);
        } catch (DownloadAdoptionRefusedException $e) {
            return $this->render($infoHash, $anime, $e->translationKey, $e->translationParams);
        }

        return new RedirectResponse($this->urlGenerator->generate('downloads_index'));
    }

    /**
     * @param array<string, string> $errorParams
     */
    private function render(string $infoHash, ?Anime $selectedAnime = null, ?string $error = null, array $errorParams = []): Response
    {
        $torrentName = null;
        try {
            $torrent = $this->adopter->findTorrent($infoHash);
            if ($torrent !== null) {
                $torrentName = (string) ($torrent['name'] ?? $infoHash);
            } elseif ($error === null) {
                $error = 'download_adopt.error_not_in_client';
            }
        } catch (QbittorrentClientException) {
            $error ??= 'download_new.error_client_unavailable';
        }

        return new Response($this->twig->render('downloads/adopt.html.twig', [
            'infoHash' => $infoHash,
            'torrentName' => $torrentName,
            'selectedAnime' => $selectedAnime,
            'error' => $error,
            'errorParams' => $errorParams,
        ]));
    }
}
