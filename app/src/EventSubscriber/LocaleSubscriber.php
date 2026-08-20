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

namespace App\EventSubscriber;

use App\Service\Plugin\AvailableLocalesProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Negotiates the request locale from the Accept-Language header.
 *
 * The available locales come from {@see AvailableLocalesProvider} (built-in locales plus enabled
 * translation plugins' locales, issue #453), not from scanning app/translations/ on every
 * request — that approach was rejected by issue #84/PR #90 for costing `glob()` I/O on every
 * main request without even covering plugin translations. `AvailableLocalesProvider::all()` is
 * called here on every main request and is deliberately NOT cached: it recomputes on every call,
 * at a small but non-zero fixed I/O cost. See the `AvailableLocalesProvider` class docblock and
 * `.claude-docs/decisions.md` (issue #84) for why that per-request cost is an accepted, documented
 * trade-off rather than an oversight.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AvailableLocalesProvider $availableLocalesProvider)
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

        $locales = $this->availableLocalesProvider->all();
        if ($locales === []) {
            return;
        }

        $request = $event->getRequest();
        $preferredLocale = $request->getPreferredLanguage($locales);
        if ($preferredLocale !== null) {
            $request->setLocale($preferredLocale);
        }
    }
}
