<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\HtmxSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class HtmxSubscriberTest extends TestCase
{
    private HtmxSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new HtmxSubscriber();
    }

    public function testGetSubscribedEvents(): void
    {
        $events = HtmxSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertSame('onKernelRequest', $events[KernelEvents::REQUEST]);
    }

    public function testOnKernelRequestSetsHtmxTrueWhenHxRequestHeaderPresent(): void
    {
        $request = new Request();
        $request->headers->set('HX-Request', 'true');

        $event = $this->createMock(RequestEvent::class);
        $event->method('getRequest')->willReturn($request);

        $this->subscriber->onKernelRequest($event);

        $this->assertTrue($request->attributes->get('_htmx'));
    }

    public function testOnKernelRequestSetsHtmxFalseWhenHxRequestHeaderAbsent(): void
    {
        $request = new Request();

        $event = $this->createMock(RequestEvent::class);
        $event->method('getRequest')->willReturn($request);

        $this->subscriber->onKernelRequest($event);

        $this->assertFalse($request->attributes->get('_htmx'));
    }
}
