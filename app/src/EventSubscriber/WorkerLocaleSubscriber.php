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

use App\Service\AppSettingsProvider;
use App\Service\NearestBuiltInLocale;
use App\Service\Plugin\AvailableLocalesProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Translation\Translator;

/**
 * Gives a background message the interface language (issue #1075). There is no request in a
 * Messenger worker, so nothing negotiates a locale and everything that reads it (the translator,
 * {@see \App\Service\Plugin\CatalogReader}, {@see \App\Service\AnimeViewFactory}) would stay on
 * `en`.
 *
 * The locale is taken from the saved setting and reconciled with
 * {@see AvailableLocalesProvider::all()} the way {@see LocaleSubscriber} does for a request: a
 * locale that is not available any more gives way to the first available one. It is done for
 * every message, not once at start: a consumer lives for the whole session and the user may switch
 * the language meanwhile. The translator's fallback chain follows the locale, as in
 * {@see LocaleSubscriber}. {@see \Symfony\Component\Translation\LocaleSwitcher} is not used: it
 * would skip that reconciliation.
 */
final class WorkerLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AppSettingsProvider $settings,
        private readonly AvailableLocalesProvider $availableLocalesProvider,
        private readonly NearestBuiltInLocale $nearestBuiltInLocale,
        #[Autowire(service: 'translator.default')]
        private readonly Translator $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $saved = $this->settings->getLocale();
        $available = $this->availableLocalesProvider->all();

        $locale = match (true) {
            $available === [] => $saved,
            $saved !== null && \in_array($saved, $available, true) => $saved,
            default => $available[0],
        };

        // Applied unconditionally: the Translator instance outlives the message, so a message that
        // skipped this would inherit the previous one's chain.
        $this->translator->setFallbackLocales($this->nearestBuiltInLocale->fallbackChain($locale));
        if ($locale !== null) {
            $this->translator->setLocale($locale);
        }
    }
}
