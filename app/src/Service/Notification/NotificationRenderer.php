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

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Service\AppSettingsProvider;
use App\Service\NearestBuiltInLocale;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one place that turns a stored notification (`kind` + `params`) into text — for the list in
 * the UI and for the WebSocket payload alike. It never throws.
 *
 * The locale is the raw one from {@see AppSettingsProvider::getLocale()}, not the request's (there
 * is no request in a php-cli worker) and not {@see NearestBuiltInLocale::resolve()}d (that folds e.g.
 * `uk` into `ru`, so a translation plugin's locale would speak a different language than the rest
 * of the UI). A render is degraded when a text is empty, equals its translation key or its
 * catalogue message uses a `%name%` that no parameter fills. Order: the user's locale as is, then the fallback chain of the
 * nearest built-in locales, then `notification.fallback.*` with a warning in the log.
 */
class NotificationRenderer
{
    private const FALLBACK_TITLE = 'notification.fallback.title';
    private const FALLBACK_BODY = 'notification.fallback.body';

    public function __construct(
        private readonly NotificationKindRegistry $registry,
        private readonly TranslatorInterface $translator,
        private readonly AppSettingsProvider $settings,
        private readonly NearestBuiltInLocale $nearestBuiltInLocale,
        private readonly NotificationSubjectLinker $linker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function renderEntity(Notification $notification): RenderedNotification
    {
        return $this->render(
            $notification->kind,
            $notification->params,
            $notification->subjectType,
            $notification->subjectId,
            $notification->occurredAt,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(
        string $kind,
        array $params,
        ?string $subjectType,
        ?string $subjectId,
        \DateTimeImmutable $occurredAt,
    ): RenderedNotification {
        try {
            $url = $this->linkFor($subjectType, $subjectId);

            $userLocale = $this->settings->getLocale();
            $definition = $this->registry->tryFrom($kind);
            if ($definition === null) {
                $this->logger->warning('Notification kind has no definition, showing the fallback text.', ['kind' => $kind]);

                return $this->fallback($occurredAt, $url, $userLocale);
            }

            $tried = [];
            foreach ([$userLocale, ...$this->nearestBuiltInLocale->fallbackChain($userLocale)] as $locale) {
                if (\in_array($locale, $tried, true)) {
                    continue;
                }
                $tried[] = $locale;

                $rendered = $this->renderInLocale($definition, $params, $locale, $url);
                if (!$rendered->degraded) {
                    return $rendered;
                }
            }

            $this->logger->warning('Notification renders degraded in every locale, showing the fallback text.', ['kind' => $kind]);

            return $this->fallback($occurredAt, $url, $userLocale);
        } catch (\Throwable $e) {
            $this->logger->error('Rendering a notification failed.', ['kind' => $kind, 'exception' => $e]);

            return $this->fallback($occurredAt, null, null);
        }
    }

    /**
     * One render attempt in exactly one locale, no fallback. The renderability gate checks this.
     *
     * @param array<string, mixed> $params
     */
    public function renderInLocale(NotificationKindDefinition $definition, array $params, ?string $locale, ?string $url = null): RenderedNotification
    {
        $degraded = false;
        $placeholders = [];
        foreach ($definition->resolveParams($params) as $name => $value) {
            $codes = $definition->params[$name]->codes;
            if ($codes !== []) {
                $codeKey = $definition->codeKey($name, (string) $value);
                $text = $this->translator->trans($codeKey, [], 'messages', $locale);
                $degraded = $degraded || self::isDegraded($text, $codeKey, $this->translator->trans($codeKey, [], 'messages', $locale), []);
                $placeholders['%'.$name.'%'] = $text;
            } else {
                $placeholders['%'.$name.'%'] = self::stringify($value);
            }
        }

        $title = $this->translator->trans($definition->titleKey(), $placeholders, 'messages', $locale);
        $body = $this->translator->trans($definition->bodyKey(), $placeholders, 'messages', $locale);
        $degraded = $degraded
            || self::isDegraded($title, $definition->titleKey(), $this->translator->trans($definition->titleKey(), [], 'messages', $locale), $placeholders)
            || self::isDegraded($body, $definition->bodyKey(), $this->translator->trans($definition->bodyKey(), [], 'messages', $locale), $placeholders);

        return new RenderedNotification($title, $body, $url, $degraded);
    }

    private function fallback(\DateTimeImmutable $occurredAt, ?string $url, ?string $locale): RenderedNotification
    {
        try {
            return new RenderedNotification(
                $this->translator->trans(self::FALLBACK_TITLE, [], 'messages', $locale),
                $this->translator->trans(self::FALLBACK_BODY, ['%date%' => $occurredAt->format('Y-m-d H:i')], 'messages', $locale),
                $url,
                true,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Rendering the fallback notification text failed.', ['exception' => $e]);

            return new RenderedNotification('', '', $url, true);
        }
    }

    private function linkFor(?string $type, ?string $id): ?string
    {
        try {
            return $this->linker->urlFor($type, $id);
        } catch (\Throwable $e) {
            $this->logger->warning('Building a notification link failed.', ['exception' => $e]);

            return null;
        }
    }

    /**
     * Unfilled placeholders are looked up in the catalogue message (`$raw`, translated without
     * parameters), never in the substituted text: a parameter value such as `%foo%` is user data.
     *
     * @param array<string, string> $provided placeholders by their `%name%` form
     */
    private static function isDegraded(string $text, string $key, string $raw, array $provided): bool
    {
        if (trim($text) === '' || $text === $key) {
            return true;
        }
        preg_match_all('/%[A-Za-z_][A-Za-z0-9_]*%/', $raw, $matches);

        return array_diff($matches[0], array_keys($provided)) !== [];
    }

    private static function stringify(string|int|bool|null $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? '1' : '0',
            $value === null => '',
            default => (string) $value,
        };
    }
}
