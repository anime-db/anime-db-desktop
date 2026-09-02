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

use App\Service\NearestBuiltInLocale;
use App\Service\Plugin\AvailableLocalesProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\Translator;

/**
 * Negotiates the request locale from the Accept-Language header, and points the translator's
 * fallback chain at the nearest built-in locale (issue #538) instead of the static `[en]` from
 * `framework.yaml`.
 *
 * The available locales come from {@see AvailableLocalesProvider} (built-in locales plus enabled
 * translation plugins' locales, issue #453), not from scanning app/translations/ on every
 * request — that approach was rejected by issue #84/PR #90 for costing `glob()` I/O on every
 * main request without even covering plugin translations. `AvailableLocalesProvider::all()` is
 * called here on every main request and is deliberately NOT cached: it recomputes on every call,
 * at a small but non-zero fixed I/O cost. See the `AvailableLocalesProvider` class docblock and
 * `.claude-docs/decisions.md` (issue #84) for why that per-request cost is an accepted, documented
 * trade-off rather than an oversight.
 *
 * `$translator` is injected by explicit service id (`translator.default`, the class behind the
 * `translator` alias/decorator chain in both prod and dev), not by interface: `setFallbackLocales()`
 * is declared only on the concrete `Symfony\Component\Translation\Translator`, not on any interface
 * it implements. Typing the property as that concrete class lets PHPStan see the method directly,
 * and injecting the id sidesteps the `DataCollectorTranslator` decorator the plain `translator`
 * alias resolves to in dev — that decorator forwards unknown calls through `__call()`, which
 * PHPStan cannot see through either.
 *
 * Priority 20 on `kernel.request` is load-bearing, not cosmetic (issue #557): every service tagged
 * `kernel.locale_aware` (`translator.default`, `translation.locale_switcher`, and any plugin-autowired
 * `SluggerInterface`, see `.claude-docs/decisions.md`) picks up its locale from
 * `Symfony\Component\HttpKernel\EventListener\LocaleAwareListener`, which itself listens on
 * `kernel.request` at priority 15. A priority at or below 15 here means that listener reads
 * `$request->getLocale()` before this method has negotiated it, so it distributes the static
 * `default_locale` from `framework.yaml` to the whole interface regardless of `Accept-Language` —
 * exactly the bug this priority fixes. 20 must stay above 15 for that reason, but does not need to
 * clear `LocaleListener::onKernelRequest()` at priority 16: that core listener only acts on a
 * `_locale` routing attribute, which no route in this app declares.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AvailableLocalesProvider $availableLocalesProvider,
        private readonly NearestBuiltInLocale $nearestBuiltInLocale,
        #[Autowire(service: 'translator.default')]
        private readonly Translator $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Deliberately unconditional, and ahead of the empty-$locales early return below: the
        // Translator instance is not recreated between requests in worker mode (issue #538), so a
        // request that skips this call would inherit whatever fallback chain the previous request
        // left behind.
        $this->translator->setFallbackLocales($this->nearestBuiltInLocale->fallbackChain($request->getPreferredLanguage()));

        $locales = $this->availableLocalesProvider->all();
        if ($locales === []) {
            return;
        }

        $preferredLocale = $request->getPreferredLanguage($locales);
        if ($preferredLocale !== null) {
            $request->setLocale($preferredLocale);
        }
    }
}
