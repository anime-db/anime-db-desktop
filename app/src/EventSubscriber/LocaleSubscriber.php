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
 * The list of available locales is not hardcoded: it is derived from the translation files
 * present in $translationsDir (domain.locale.format, e.g. messages.ru.yaml), so a locale added
 * later - including by a plugin - is picked up without a code change.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $translationsDir)
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

        $locales = $this->getAvailableLocales();
        if ([] === $locales) {
            return;
        }

        $request = $event->getRequest();
        $preferredLocale = $request->getPreferredLanguage($locales);
        if (null !== $preferredLocale) {
            $request->setLocale($preferredLocale);
        }
    }

    /**
     * @return list<string>
     */
    private function getAvailableLocales(): array
    {
        $files = glob($this->translationsDir.'/*.*.*') ?: [];

        $locales = [];
        foreach ($files as $file) {
            // translation file name format is "domain.locale.format", e.g. "messages.ru.yaml"
            $parts = explode('.', basename($file));
            if (\count($parts) >= 3) {
                $locales[] = $parts[\count($parts) - 2];
            }
        }

        return array_values(array_unique($locales));
    }
}
