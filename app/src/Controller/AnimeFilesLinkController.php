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
use App\Service\AnimeViewFactory;
use App\Service\Storage\ManualLinkResult;
use App\Service\Storage\ManualLinkService;
use App\Service\Storage\ManualLinkStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Manual link of an entry to a top-level item of a storage, and its removal (issue #997), from the
 * "Files" block of the entry card. The work and every refusal live in {@see ManualLinkService};
 * this controller re-renders the block as an HTMX fragment with the outcome inside it. The one
 * exception is a storage that moved: that answer is a 409 JSON the client turns into a
 * confirmation, then repeats the request with `relocate_storage_id`.
 */
final class AnimeFilesLinkController
{
    public function __construct(
        private readonly ManualLinkService $linkService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AnimeViewFactory $viewFactory,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/anime/{id}/link-files', name: 'anime_link_files', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function link(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken($anime, $request);

        $relocateId = $request->request->get('relocate_storage_id');
        $result = $this->linkService->link(
            $anime,
            (string) $request->request->get('path', ''),
            \is_scalar($relocateId) && ctype_digit((string) $relocateId) ? (int) $relocateId : null,
        );

        if ($result->status === ManualLinkStatus::RelocateRequired) {
            $storage = $result->storage ?? throw new \LogicException('A relocation answer always names its storage.');

            return new JsonResponse(['relocate' => [
                'storage_id' => $storage->id,
                'name' => $storage->getName(),
                'old_path' => $storage->getPath(),
                'new_path' => $result->path,
            ]], Response::HTTP_CONFLICT);
        }

        return $this->renderFiles($anime, $this->messageFor($result));
    }

    #[Route('/anime/{id}/unlink-files', name: 'anime_unlink_files', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlink(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken($anime, $request);

        $this->linkService->unlink($anime);

        return $this->renderFiles($anime, ['kind' => 'notice', 'key' => 'anime_detail.files_unlinked', 'params' => []]);
    }

    /** @return array{kind: string, key: string, params: array<string, string>, link_url?: string, link_key?: string, link_text?: string} */
    private function messageFor(ManualLinkResult $result): array
    {
        $storageName = $result->storage?->getName() ?? '';
        $entry = $result->entryName ?? '';
        $params = ['%storage%' => $storageName, '%name%' => $entry];

        return match ($result->status) {
            ManualLinkStatus::Linked => [
                'kind' => 'notice',
                'key' => $result->nested ? 'anime_detail.files_linked_top_level' : 'anime_detail.files_linked',
                'params' => $params,
            ],
            ManualLinkStatus::OutsideStorages => [
                'kind' => 'error',
                'key' => 'anime_detail.files_error_outside_storages',
                'params' => [],
                'link_url' => $this->urlGenerator->generate('storage_new', ['path' => $result->path]),
                'link_key' => 'anime_detail.files_create_storage',
            ],
            ManualLinkStatus::Occupied => [
                'kind' => 'error',
                'key' => 'anime_detail.files_error_occupied',
                'params' => $params,
                'link_url' => $this->urlGenerator->generate('anime_show', ['id' => $result->occupiedBy?->id]),
                'link_text' => $result->occupiedBy?->getTitle() ?? '',
            ],
            default => [
                'kind' => 'error',
                'key' => 'anime_detail.files_error_'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $result->status->name) ?? ''),
                'params' => $params,
            ],
        };
    }

    /** @param array<string, mixed> $message */
    private function renderFiles(Anime $anime, array $message): Response
    {
        return new Response($this->twig->render('anime/_files.html.twig', [
            'anime' => $this->viewFactory->serialize($anime),
            'files_message' => $message,
        ]));
    }

    private function assertValidCsrfToken(Anime $anime, Request $request): void
    {
        $token = new CsrfToken('anime_files_'.$anime->id, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
