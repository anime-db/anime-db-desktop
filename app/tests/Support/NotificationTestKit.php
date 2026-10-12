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

namespace App\Tests\Support;

use App\Service\Notification\Kind\NotificationKindInterface;
use App\Service\Notification\NotificationDraft;
use App\Service\Notification\NotificationKindDefinition;
use App\Service\Notification\NotificationMergePolicy;
use App\Service\Notification\NotificationParam;
use App\Service\Notification\NotificationParamType;
use App\Service\Notification\NotificationSeverity;

/**
 * A test-only notification kind (the production code has none yet) with its draft factory, used to
 * exercise the mechanism: merge policies, rendering, the renderability gate.
 */
final class NotificationTestKit implements NotificationKindInterface
{
    public const KIND = 'test_event';

    public function __construct(
        private readonly NotificationMergePolicy $policy = NotificationMergePolicy::CoalesceUnread,
        private readonly string $kind = self::KIND,
    ) {
    }

    public static function testEvent(string $name, int $count, string $reason, ?int $animeId = null): NotificationDraft
    {
        return new NotificationDraft(
            self::KIND,
            ['name' => $name, 'count' => $count, 'reason' => $reason],
            $animeId !== null ? 'anime' : null,
            $animeId !== null ? (string) $animeId : null,
            'test_event:'.$name,
        );
    }

    /** A draft of another kind with the given raw merge key, to check that keys are kept apart per kind. */
    public static function otherKindDraft(string $kind, string $name, string $dedupKey): NotificationDraft
    {
        return new NotificationDraft($kind, ['name' => $name, 'count' => 1, 'reason' => 'network'], null, null, $dedupKey);
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            $this->kind,
            NotificationSeverity::Warning,
            $this->policy,
            [
                'name' => new NotificationParam(NotificationParamType::String),
                // added after v1 of the kind: carries a default for older records
                'count' => new NotificationParam(NotificationParamType::Int, hasDefault: true, default: 0),
                'reason' => new NotificationParam(NotificationParamType::String, codes: ['disk_space', 'network']),
            ],
            static fn (): NotificationDraft => self::testEvent('Sample', 3, 'disk_space', 7),
        );
    }

    /**
     * @return array<string, array<string, string>> messages by locale
     */
    public static function messages(): array
    {
        return [
            'en' => [
                'notification.test_event.title' => 'Test event: %name%',
                'notification.test_event.body' => '%count% items, reason: %reason%',
                'notification.test_event.reason.disk_space' => 'no disk space',
                'notification.test_event.reason.network' => 'network error',
                'notification.fallback.title' => 'Notification',
                'notification.fallback.body' => 'Could not display the notification text from %date%.',
            ],
            'ru' => [
                'notification.test_event.title' => 'Тестовое событие: %name%',
                'notification.test_event.body' => 'Элементов: %count%, причина: %reason%',
                'notification.test_event.reason.disk_space' => 'нет места на диске',
                'notification.test_event.reason.network' => 'сетевая ошибка',
                'notification.fallback.title' => 'Оповещение',
                'notification.fallback.body' => 'Не удалось показать текст оповещения от %date%.',
            ],
        ];
    }

    public static function logger(): NotificationTestLogger
    {
        return new NotificationTestLogger();
    }
}
