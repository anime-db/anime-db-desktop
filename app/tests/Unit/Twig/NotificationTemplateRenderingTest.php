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

namespace App\Tests\Unit\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Markup of the notification list (issue #1025): a record with a living subject is a link, one
 * without is plain text from the stored params; the header carries the bell and no delete control.
 */
final class NotificationTemplateRenderingTest extends KernelTestCase
{
    public function testAnItemWithAUrlIsALinkAndOneWithoutIsPlainText(): void
    {
        $html = $this->render('notifications/_dropdown.html.twig', ['items' => [
            $this->item(1, 'Linked title', '/anime/5'),
            $this->item(2, 'Plain title', null),
        ]]);

        self::assertStringContainsString('<a href="/anime/5">Linked title</a>', $html);
        self::assertStringContainsString('Plain title', $html);
        self::assertStringNotContainsString('<a href="">', $html);
        self::assertStringNotContainsString('>Plain title</a>', $html);
        self::assertSame(2, substr_count($html, 'data-notification-unread'));
    }

    public function testNoDeleteControlIsRenderedInTheListOrOnTheHistoryPage(): void
    {
        $items = [$this->item(1, 'A', null)];

        foreach ([
            $this->render('notifications/_dropdown.html.twig', ['items' => $items]),
            $this->render('notifications/index.html.twig', ['items' => $items]),
        ] as $html) {
            self::assertStringNotContainsStringIgnoringCase('delete', $html);
            self::assertStringNotContainsString('data-icon="trash"', $html);
        }
    }

    public function testTheHistoryPageHasAReadAllFormWithACsrfToken(): void
    {
        $html = $this->render('notifications/index.html.twig', ['items' => []]);

        self::assertMatchesRegularExpression('#<form method="post" action="/notifications/read-all">\s*<input type="hidden" name="_token" value="[^"]+">#', $html);
    }

    public function testTheHeaderHasTheBellWithAHiddenBadgeWhenNothingIsUnread(): void
    {
        $html = $this->render('base.html.twig', []);

        self::assertStringContainsString('data-control="notifications-bell"', $html);
        self::assertMatchesRegularExpression('#<span class="app-nav__bell-badge" data-bell-badge hidden>0</span>#', $html);
    }

    /** @return array<string, mixed> */
    private function item(int $id, string $title, ?string $url): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'body' => '',
            'url' => $url,
            'occurredAt' => new \DateTimeImmutable('2026-01-02 03:04:00'),
            'severity' => 'info',
            'read' => false,
        ];
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        self::bootKernel();
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render($template, $context);
    }
}
