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
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Service\Storage\StorageLocationKind;
use App\Service\Storage\StorageLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolver for a folder path handed over by the desktop shell (issue #1121): finds out what the
 * database knows about the folder and redirects to the screen that fits. It only reads and
 * redirects: nothing is moved, linked or created here, every change waits for a confirmation on
 * the screen the redirect leads to.
 *
 * The route answers only a request carrying {@see self::SHELL_HEADER}, which the shell sets for its
 * own window. A page opened in a browser cannot send a custom header cross-site without a CORS
 * preflight the backend never approves, and `<img>` or a plain link send none. The check is the
 * first thing the action does: reading a marker or testing a UNC path (`\\host\share`) makes the
 * machine talk to that host, so any filesystem access before the check would leak credentials.
 *
 * The header alone does not stop DNS rebinding: a page on a foreign domain re-pointed at 127.0.0.1
 * is same-origin with the backend and may set any header. So the `Host` must be a loopback name too.
 */
final class ExplorerOpenController
{
    public const string SHELL_HEADER = ShellRequestGuard::SHELL_HEADER;
    public const string SHELL_HEADER_VALUE = ShellRequestGuard::SHELL_HEADER_VALUE;

    public function __construct(
        private readonly StorageLocator $locator,
        private readonly AnimeRepository $animes,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/explorer/open', name: 'explorer_open', methods: ['GET'])]
    public function open(Request $request): Response
    {
        if (!ShellRequestGuard::isShellRequest($request)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $selectedPath = trim((string) $request->query->get('path', ''));
        $location = $this->locator->locate($selectedPath);

        if ($location->kind === StorageLocationKind::InvalidPath) {
            return $this->storageListWithError($request);
        }

        if ($location->kind === StorageLocationKind::Outside) {
            // The shell only offers this action for folders and drives, so the selected path itself is the folder to
            // suggest; $location->suggestedStoragePath (the parent) is for the manual link flow, which selects items.
            return $this->redirectTo('storage_new', ['path' => $selectedPath]);
        }

        $storage = $location->storage ?? throw new \LogicException('A found location always names its storage.');
        $storageId = $storage->id ?? throw new \LogicException('A found storage is persisted.');

        if ($location->relocated) {
            return $this->redirectTo('storage_edit', [
                'id' => $storageId,
                'path' => $this->locator->absoluteRoot($location->rootPath ?? ''),
            ]);
        }

        if ($location->isRoot()) {
            return $this->redirectTo('storage_index');
        }

        $name = $location->topLevelName();
        if ($name === null) {
            return $this->storageListWithError($request);
        }

        $holder = $this->findHolder($storage, $name);
        if ($holder !== null) {
            return $this->redirectTo('anime_show', ['id' => $holder->id]);
        }

        return $this->redirectTo('anime_new', ['storage_id' => $storageId, 'storage_path' => $name, 'title' => $name]);
    }

    /** The entry bound to the top-level item, comparing names ignoring case and a trailing separator (v1 imports store "Foo\"). */
    private function findHolder(Storage $storage, string $name): ?Anime
    {
        $key = mb_strtolower($name);
        foreach ($this->animes->findByStorage($storage) as $anime) {
            if (mb_strtolower(rtrim((string) $anime->getStoragePath(), '\\/')) === $key) {
                return $anime;
            }
        }

        return null;
    }

    private function storageListWithError(Request $request): Response
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('danger', [
            'text' => $this->translator->trans('explorer_open.error_invalid_path'),
            'link_url' => null,
            'link_label' => null,
        ]);

        return $this->redirectTo('storage_index');
    }

    /** @param array<string, int|string|null> $parameters */
    private function redirectTo(string $route, array $parameters = []): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate($route, $parameters));
    }
}
