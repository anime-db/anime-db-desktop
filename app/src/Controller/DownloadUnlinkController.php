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

use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadUnlinkService;
use App\Service\Download\DownloadViewFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The anime page's "Unlink" button (issue #857) — same {@see DownloadUnlinkService} transaction as
 * `app:downloads:unlink`, triggered from the "Downloads for this entry" block instead of the
 * console. Re-renders that block as an HTMX fragment swap (same shape as AnimeEditableController),
 * with a translated error in place of the removed row when the version-checked delete lost its
 * race with DownloadCompletionPoller (issue #837) — the row itself is left exactly as the service's
 * rolled-back transaction found it.
 */
final class DownloadUnlinkController
{
    public function __construct(
        private readonly DownloadRepository $downloads,
        private readonly DownloadUnlinkService $unlinker,
        private readonly DownloadViewFactory $downloadViewFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/downloads/{id}/unlink', name: 'download_unlink', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlink(Download $download, Request $request): Response
    {
        $downloadId = $download->id ?? throw new \LogicException('Download must be persisted before it can be unlinked.');
        $token = new CsrfToken('download_unlink_'.$downloadId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $animeId = $download->getAnime()->id ?? throw new \LogicException('Anime must be persisted before its downloads can be rendered.');
        $expected = $this->parseExpectedState($request);
        $succeeded = $expected !== null && $this->unlinker->unlink($download, $expected[0], $expected[1])->succeeded;

        return new Response($this->twig->render('anime/_downloads.html.twig', [
            'anime' => ['id' => $animeId],
            'downloads' => $this->downloadViewFactory->serializeList($this->downloads->findByAnime($animeId)),
            'error' => $succeeded ? null : 'anime_detail.downloads_unlink_conflict_error',
        ]));
    }

    /**
     * Reads the `version`/`status` hidden fields the row was rendered with. Missing or unparseable
     * fields are treated as a state mismatch: the service is not called at all.
     *
     * @return ?array{0: int, 1: DownloadStatus}
     */
    private function parseExpectedState(Request $request): ?array
    {
        $version = $request->request->get('version');
        if (!\is_string($version) || !ctype_digit($version)) {
            return null;
        }

        $status = $request->request->get('status');
        $status = \is_string($status) ? DownloadStatus::tryFrom($status) : null;
        if ($status === null) {
            return null;
        }

        return [(int) $version, $status];
    }
}
