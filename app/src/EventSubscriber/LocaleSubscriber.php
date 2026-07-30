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

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Negotiates the request locale from the Accept-Language header.
 *
 * The available locales come from the app.locales container parameter (services.yaml), not from
 * scanning app/translations/ on every request: the subscriber is a singleton that survives
 * between requests in FrankenPHP worker mode, so a filesystem scan there would be per-request I/O
 * for a locale set that never changes at runtime.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $locales
     */
    public function __construct(private readonly array $locales)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->locales === []) {
            return;
        }

        $request = $event->getRequest();
        $preferredLocale = $request->getPreferredLanguage($this->locales);
        if ($preferredLocale !== null) {
            $request->setLocale($preferredLocale);
        }
    }
}
