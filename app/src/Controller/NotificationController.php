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

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationRenderer;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 * The notification history in the UI (issue #1025): the header bell's dropdown list, the history
 * page, and the two "read" marks. There is no way to delete a record here.
 *
 * Opening a list marks what it showed as read: the history page does it while handling the GET, the
 * dropdown by a separate POST of exactly the ids it showed (a record that arrived after the list
 * was built must stay unread).
 */
final class NotificationController
{
    public const string CSRF_TOKEN_ID = 'notifications';
    private const int DROPDOWN_LIMIT = 10;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationRenderer $renderer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/notifications/recent', name: 'notifications_recent', methods: ['GET'])]
    public function recent(): Response
    {
        return new Response($this->twig->render('notifications/_dropdown.html.twig', [
            'items' => $this->present($this->notifications->findRecent(self::DROPDOWN_LIMIT)),
        ]));
    }

    #[Route('/notifications', name: 'notifications_index', methods: ['GET'])]
    public function index(): Response
    {
        $records = $this->notifications->findAll();
        $items = $this->present($records);

        // Marked before rendering, so the header bell of this very page already shows the new count;
        // `read` of each item was taken above, so the page still tells what was new.
        $this->notifications->markRead(self::unreadIds($items));

        return new Response($this->twig->render('notifications/index.html.twig', ['items' => $items]));
    }

    #[Route('/notifications/read', name: 'notifications_mark_read', methods: ['POST'])]
    public function markRead(Request $request): Response
    {
        $this->assertTokenValid($request);

        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (\is_scalar($id) && preg_match('/^\d+$/', (string) $id) === 1) {
                $ids[] = (int) $id;
            }
        }
        $this->notifications->markRead($ids);

        return new JsonResponse(['unread_count' => $this->notifications->countUnread()]);
    }

    #[Route('/notifications/read-all', name: 'notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        $this->assertTokenValid($request);

        $this->notifications->markAllRead();

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['unread_count' => $this->notifications->countUnread()]);
        }

        return new RedirectResponse($this->urlGenerator->generate('notifications_index'));
    }

    private function assertTokenValid(Request $request): void
    {
        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @param list<Notification> $records
     *
     * @return list<array{id: int, title: string, body: string, url: ?string, occurredAt: \DateTimeImmutable, severity: string, read: bool}>
     */
    private function present(array $records): array
    {
        $items = [];
        foreach ($records as $record) {
            $rendered = $this->renderer->renderEntity($record);
            $items[] = [
                'id' => $record->id ?? throw new \LogicException('A stored notification has an id.'),
                'title' => $rendered->title,
                'body' => $rendered->body,
                'url' => $rendered->url,
                'occurredAt' => $record->occurredAt,
                'severity' => $record->severity,
                'read' => $record->isRead(),
            ];
        }

        return $items;
    }

    /**
     * @param list<array{id: int, read: bool}> $items
     *
     * @return list<int>
     */
    private static function unreadIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!$item['read']) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }
}
