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

namespace App\Tests\Unit\Controller;

use App\Controller\NotificationController;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\RenderedNotification;
use App\Tests\Support\CreatesInMemoryEntityManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class NotificationControllerTest extends TestCase
{
    use CreatesInMemoryEntityManager;

    private EntityManager $entityManager;
    private NotificationRepository $repository;
    /** @var array<string, array<string, mixed>> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
        $this->repository = new NotificationRepository($this->entityManager);
        $this->rendered = [];
    }

    public function testHistoryShowsEveryRecordAndMarksTheShownOnesRead(): void
    {
        $first = $this->persist('a', 100);
        $second = $this->persist('b', 200);
        $second->markRead();
        $this->entityManager->flush();

        $this->controller()->index();

        $items = $this->rendered['notifications/index.html.twig']['items'];
        self::assertSame([$second->id, $first->id], array_column($items, 'id'));
        // `read` is what the page was opened with, so it can still tell what was new.
        self::assertSame([true, false], array_column($items, 'read'));
        self::assertSame(0, $this->repository->countUnread());
    }

    public function testHistoryMarksOnlyTheRecordsItShowedNotOnesThatArrivedMeanwhile(): void
    {
        $shown = $this->persist('a', 100);
        $test = $this;
        $this->repository = new class($this->entityManager, $test) extends NotificationRepository {
            public function __construct(EntityManager $em, private readonly NotificationControllerTest $test)
            {
                parent::__construct($em);
            }

            public function findAll(): array
            {
                $records = parent::findAll();
                $this->test->persistLate();

                return $records;
            }
        };

        $this->controller()->index();

        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(Notification::class, $shown->id)?->isRead());
        self::assertSame(1, $this->repository->countUnread());
    }

    public function testDropdownListIsLimitedAndDoesNotMarkAnythingRead(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->persist('n'.$i, $i);
        }

        $this->controller()->recent();

        self::assertCount(10, $this->rendered['notifications/_dropdown.html.twig']['items']);
        self::assertSame(12, $this->repository->countUnread());
    }

    public function testAnItemCarriesTheRenderedTextAndTheLinkOfItsSubject(): void
    {
        $this->persist('a', 100);

        $this->controller()->recent();

        $item = $this->rendered['notifications/_dropdown.html.twig']['items'][0];
        self::assertSame('Title a', $item['title']);
        self::assertSame('/anime/5', $item['url']);
    }

    public function testMarkReadMarksOnlyTheGivenIdsAndReturnsTheCount(): void
    {
        $shown = $this->persist('a', 100);
        $late = $this->persist('late', 200);

        $response = $this->controller()->markRead($this->post(['ids' => [(string) $shown->id, 'junk'], '_token' => 'ok']));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"unread_count":1}', $response->getContent());

        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(Notification::class, $shown->id)?->isRead());
        self::assertFalse($this->entityManager->find(Notification::class, $late->id)?->isRead());
    }

    public function testMarkReadWithAnInvalidTokenIsRejectedAndChangesNothing(): void
    {
        $record = $this->persist('a', 100);

        try {
            $this->controller()->markRead($this->post(['ids' => [(string) $record->id], '_token' => 'bad']));
            self::fail('A bad CSRF token must be rejected.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame(1, $this->repository->countUnread());
    }

    public function testReadAllMarksEveryRecordAndRedirectsToTheHistory(): void
    {
        $this->persist('a', 100);
        $this->persist('b', 200);

        $response = $this->controller()->readAll($this->post(['_token' => 'ok']));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/notifications', $response->getTargetUrl());
        self::assertSame(0, $this->repository->countUnread());
    }

    public function testReadAllAnswersJsonToAnAjaxCaller(): void
    {
        $this->persist('a', 100);
        $request = $this->post(['_token' => 'ok']);
        $request->headers->set('Accept', 'application/json');

        $response = $this->controller()->readAll($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"unread_count":0}', $response->getContent());
    }

    public function testReadAllWithAnInvalidTokenIsRejectedAndChangesNothing(): void
    {
        $this->persist('a', 100);

        try {
            $this->controller()->readAll($this->post(['_token' => 'bad']));
            self::fail('A bad CSRF token must be rejected.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame(1, $this->repository->countUnread());
    }

    public function testReadAllWithoutATokenIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller()->readAll($this->post([]));
    }

    /** @internal for the repository spy of the history test */
    public function persistLate(): void
    {
        $this->persist('late', 300);
    }

    /** @param array<string, mixed> $data */
    private function post(array $data): Request
    {
        return new Request([], $data, [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    private function persist(string $name, int $occurredAt): Notification
    {
        $record = new Notification('test_event', 'info', ['name' => $name], 'anime', '5', null, (new \DateTimeImmutable())->setTimestamp($occurredAt));
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }

    private function controller(): NotificationController
    {
        $renderer = $this->createStub(NotificationRenderer::class);
        $renderer->method('renderEntity')->willReturnCallback(
            static fn (Notification $n) => new RenderedNotification('Title '.$n->params['name'], 'Body', '/anime/5', false),
        );

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token) => $token->getId() === NotificationController::CSRF_TOKEN_ID && $token->getValue() === 'ok',
        );

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/notifications');

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context = []): string {
            $this->rendered[$template] = $context;

            return '';
        });

        return new NotificationController($this->repository, $renderer, $csrf, $urls, $twig);
    }
}
